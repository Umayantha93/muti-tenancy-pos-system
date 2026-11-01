<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Expense;
use App\Models\Part;
use App\Models\StockReceipt;
use App\Models\StockReceiptItem;
use App\Models\Tenant;
use App\Services\BranchInventory;
use App\Support\InventoryCosting;
use App\Support\InventoryExpenseAmounts;
use App\Support\StockUnit;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StockReceiptController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $term = trim((string) $request->input('search', ''));
        $like = '%'.addcslashes($term, '%_\\').'%';
        $perPage = min(max($request->integer('per_page', 20), 1), 100);

        $receipts = StockReceipt::query()
            ->with([
                'supplier:id,name',
                'items' => fn ($query) => $query->where('item_type', 'part'),
                'items.part:id,name,sku,barcode,stock_unit',
            ])
            ->whereNotNull('invoice_number')
            ->where('invoice_number', '!=', '')
            ->whereHas('items', fn ($query) => $query->where('item_type', 'part'))
            ->when($term !== '', fn ($query) => $query->where(fn ($nested) => $nested
                ->where('invoice_number', 'like', $like)
                ->orWhere('receipt_number', 'like', $like)
                ->orWhereHas('supplier', fn ($supplier) => $supplier->where('name', 'like', $like))))
            ->latest('received_at')
            ->latest('id')
            ->paginate($perPage);

        $receipts->getCollection()->transform(fn (StockReceipt $receipt) => [
            'id' => $receipt->id,
            'receipt_number' => $receipt->receipt_number,
            'invoice_number' => $receipt->invoice_number,
            'received_at' => $receipt->received_at?->toDateString(),
            'payment_status' => $receipt->payment_status,
            'due_date' => $receipt->due_date?->toDateString(),
            'supplier' => $receipt->supplier ? ['id' => $receipt->supplier->id, 'name' => $receipt->supplier->name] : null,
            'total' => round((float) $receipt->items->sum(
                fn (StockReceiptItem $item) => (float) $item->quantity * (float) $item->unit_cost
            ), 2),
            'items' => $receipt->items->map(fn (StockReceiptItem $item) => [
                'id' => $item->id,
                'part_id' => $item->part_id,
                'name' => $item->part?->name ?? 'Deleted item',
                'sku' => $item->part?->sku,
                'stock_unit' => $item->part?->stock_unit,
                'quantity' => (float) $item->quantity,
                'unit_cost' => $item->unit_cost,
                'line_total' => round((float) $item->quantity * (float) $item->unit_cost, 2),
            ])->values(),
        ]);

        return $this->moneyJson($receipts);
    }

    public function update(Request $request, StockReceipt $stockReceipt): JsonResponse
    {
        $data = $request->validate([
            'invoice_number' => ['required', 'string', 'max:80'],
            'payment_status' => ['required', Rule::in(['paid', 'credit'])],
            'due_date' => ['nullable', 'date', 'required_if:payment_status,credit'],
            'items' => ['sometimes', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ]);

        $invoice = trim($data['invoice_number']);
        $status = $data['payment_status'];
        $dueDate = $status === 'credit' ? $data['due_date'] : null;

        DB::transaction(function () use ($stockReceipt, $data, $invoice, $status, $dueDate) {
            if (isset($data['items'])) {
                $this->correctLines($stockReceipt, $data['items']);
            }

            $expense = $stockReceipt->expense()->first()
                ?? Expense::query()->where('stock_receipt_id', $stockReceipt->id)->first();
            $statusChanged = $expense && $status !== $stockReceipt->payment_status;
            if ($statusChanged) {
                $locked = $expense->settlements()->exists()
                    || $expense->cheques()->exists()
                    || ($expense->isCredit() && (float) $expense->amount_paid > 0.001);
                if ($locked) {
                    throw ValidationException::withMessages([
                        'payment_status' => ['This GRN already has a payment or cheque. Change it from Finance instead.'],
                    ]);
                }
                $paid = $status === Expense::STATUS_PAID;
                $expense->forceFill([
                    'payment_status' => $paid ? Expense::STATUS_PAID : Expense::STATUS_CREDIT,
                    'due_date' => $paid ? null : $dueDate,
                    'amount_paid' => $paid ? $expense->amount : 0,
                    'settled_at' => $paid ? ($expense->expense_date ?? now()) : null,
                    'description' => $this->descriptionWithInvoice($expense->description, $stockReceipt->invoice_number, $invoice),
                ])->save();
            } elseif ($expense) {
                $expense->forceFill([
                    'due_date' => $status === 'credit' ? $dueDate : null,
                    'description' => $this->descriptionWithInvoice($expense->description, $stockReceipt->invoice_number, $invoice),
                ])->save();
            }

            $stockReceipt->update([
                'invoice_number' => $invoice,
                'payment_status' => $status,
                'due_date' => $dueDate,
            ]);

            if (isset($data['items'])) {
                $this->syncExpenseTotal($stockReceipt->fresh(), $status, $dueDate);
            }
        });

        $stockReceipt->load([
            'supplier:id,name',
            'items' => fn ($query) => $query->where('item_type', 'part'),
            'items.part:id,name,sku,barcode,stock_unit',
        ]);

        return $this->moneyJson($this->receiptPayload($stockReceipt));
    }

    public function report(Request $request): Response
    {
        $data = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ]);
        $from = \Illuminate\Support\Carbon::parse($data['from'])->toDateString();
        $to = \Illuminate\Support\Carbon::parse($data['to'])->toDateString();

        $receipts = StockReceipt::query()
            ->with([
                'supplier:id,name',
                'items.part:id,name,sku',
                'items.product:id,name',
            ])
            ->enteredBetween($from, $to)
            ->orderBy('received_at')
            ->orderBy('id')
            ->get();

        $business = $request->user()->tenant?->business_name ?? 'Business';
        $shop = $request->user()->role === 'staff' ? \App\Support\BranchContext::branch()?->name : null;
        $filename = sprintf('grn-report-%s-to-%s.pdf', $from, $to);

        $options = new Options;
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->reportHtml($receipts, $business, $shop, $from, $to), 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, StockReceipt>  $receipts
     */
    private function reportHtml($receipts, string $business, ?string $shop, string $from, string $to): string
    {
        $e = fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
        $money = fn ($value): string => number_format((float) $value, 2);
        $qty = function ($value): string {
            $formatted = number_format((float) $value, 3, '.', '');

            return rtrim(rtrim($formatted, '0'), '.') ?: '0';
        };
        $period = \Illuminate\Support\Carbon::parse($from)->format('j M Y').' – '.\Illuminate\Support\Carbon::parse($to)->format('j M Y');

        $html = '<html><head><meta charset="utf-8"><style>
            body { font-family: "DejaVu Sans", sans-serif; font-size: 10px; color: #20221f; }
            h1 { font-size: 16px; margin: 0; }
            h2 { font-size: 12px; margin: 14px 0 2px; }
            p.sub { margin: 2px 0 6px; color: #4f544e; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
            th, td { border: 1px solid #d7d3c8; padding: 4px 6px; vertical-align: top; }
            th { background: #167c73; color: #fff; text-align: left; font-size: 9px; text-transform: uppercase; }
            td.num, th.num { text-align: right; white-space: nowrap; }
            tr.total td { font-weight: bold; background: #f3f0e8; }
        </style></head><body>';
        $html .= '<h1>'.$e($business).'</h1>';
        $html .= '<p class="sub">GRN report · entered '.$e($period).($shop ? ' · '.$e($shop) : '').'<br>Each invoice is its own table. Dates are the day the GRN was entered, not the payment due date.</p>';

        $grand = 0.0;
        if ($receipts->isEmpty()) {
            $html .= '<p class="sub">No GRNs were entered in this period.</p>';
        }
        foreach ($receipts as $receipt) {
            $lines = $receipt->items;
            $receiptTotal = round((float) $lines->sum(
                fn (StockReceiptItem $item) => (float) $item->quantity * (float) $item->unit_cost
            ), 2);
            $grand += $receiptTotal;
            $entered = $receipt->received_at?->toDateString() ?? $receipt->created_at?->toDateString();
            $enteredLabel = $entered ? \Illuminate\Support\Carbon::parse($entered)->format('j M Y') : '—';
            $invoice = $receipt->invoice_number ?: '—';
            $payment = $receipt->payment_status === 'credit' ? 'Credit' : 'Paid';
            $due = $receipt->due_date?->toDateString() ?: '—';
            $html .= '<h2>Invoice '.$e($invoice).' · '.$e($enteredLabel).'</h2>';
            $html .= '<p class="sub">'.$e($receipt->receipt_number)
                .' · '.($receipt->supplier?->name ? $e($receipt->supplier->name) : 'No supplier')
                .' · '.$e($payment)
                .' · Due '.$e($due)
                .'</p>';
            $html .= '<table><thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Unit cost</th><th class="num">Amount</th></tr></thead><tbody>';
            if ($lines->isEmpty()) {
                $html .= '<tr><td colspan="4">No lines on this invoice.</td></tr>';
            }
            foreach ($lines as $item) {
                $name = $item->part?->name ?? $item->product?->name ?? 'Deleted item';
                if ($item->part?->sku) {
                    $name .= ' ('.$item->part->sku.')';
                }
                $lineAmount = (float) $item->quantity * (float) $item->unit_cost;
                $html .= '<tr><td>'.$e($name).'</td><td class="num">'.$e($qty($item->quantity)).'</td><td class="num">'.$e($money($item->unit_cost)).'</td><td class="num">'.$e($money($lineAmount)).'</td></tr>';
            }
            $html .= '<tr class="total"><td colspan="3">Invoice total</td><td class="num">LKR '.$money($receiptTotal).'</td></tr>';
            $html .= '</tbody></table>';
        }
        if ($receipts->isNotEmpty()) {
            $html .= '<table><tr class="total"><td>All invoices</td><td class="num">LKR '.$money($grand).'</td></tr></table>';
        }
        $html .= '</body></html>';

        return $html;
    }

    /**
     * @return array<string, mixed>
     */
    private function receiptPayload(StockReceipt $stockReceipt): array
    {
        return [
            'id' => $stockReceipt->id,
            'receipt_number' => $stockReceipt->receipt_number,
            'invoice_number' => $stockReceipt->invoice_number,
            'received_at' => $stockReceipt->received_at?->toDateString(),
            'payment_status' => $stockReceipt->payment_status,
            'due_date' => $stockReceipt->due_date?->toDateString(),
            'supplier' => $stockReceipt->supplier ? ['id' => $stockReceipt->supplier->id, 'name' => $stockReceipt->supplier->name] : null,
            'total' => round((float) $stockReceipt->items->sum(
                fn (StockReceiptItem $item) => (float) $item->quantity * (float) $item->unit_cost
            ), 2),
            'items' => $stockReceipt->items->map(fn (StockReceiptItem $item) => [
                'id' => $item->id,
                'part_id' => $item->part_id,
                'name' => $item->part?->name ?? 'Deleted item',
                'sku' => $item->part?->sku,
                'stock_unit' => $item->part?->stock_unit,
                'quantity' => (float) $item->quantity,
                'unit_cost' => $item->unit_cost,
                'line_total' => round((float) $item->quantity * (float) $item->unit_cost, 2),
            ])->values(),
        ];
    }

    /**
     * @param  list<array{id: int, quantity: float|int|string, unit_cost: float|int|string}>  $submitted
     */
    private function correctLines(StockReceipt $stockReceipt, array $submitted): void
    {
        $stockReceipt->load(['items.part']);
        $partLines = $stockReceipt->items->where('item_type', 'part')->keyBy(fn (StockReceiptItem $item) => (int) $item->id);
        $givenIds = collect($submitted)->map(fn (array $row) => (int) $row['id']);
        $expected = $partLines->keys()->map(fn ($id) => (int) $id)->sort()->values();
        $given = $givenIds->unique()->sort()->values();
        if ($givenIds->count() !== $given->count() || $expected->all() !== $given->all()) {
            throw ValidationException::withMessages([
                'items' => ['Send every line on this GRN.'],
            ]);
        }

        $partIds = $partLines->pluck('part_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
        $parts = $partIds->isEmpty()
            ? collect()
            : Part::query()->whereIn('id', $partIds->all())->lockForUpdate()->get()->keyBy('id');
        $branchId = $stockReceipt->branch_id ?: Branch::defaultIdFor($stockReceipt->tenant_id);
        $businessType = Tenant::query()->find($stockReceipt->tenant_id)?->business_type;
        $method = Tenant::query()->find($stockReceipt->tenant_id)?->inventory_cost_method;

        $changes = [];
        $deltas = [];
        foreach ($submitted as $index => $row) {
            $line = $partLines->get((int) $row['id']);
            $part = $line?->part_id ? $parts->get((int) $line->part_id) : null;
            $newQty = round((float) $row['quantity'], 3);
            $newCost = round((float) $row['unit_cost'], 2);
            $oldQty = round((float) $line->quantity, 3);
            if ($part && ($error = StockUnit::quantityError($part->stock_unit, $newQty, $businessType))) {
                throw ValidationException::withMessages(["items.$index.quantity" => [$error]]);
            }
            if (! $part && abs($newQty - $oldQty) > 0.001) {
                throw ValidationException::withMessages([
                    "items.$index.quantity" => ['This item is no longer in the catalogue, so its quantity cannot change.'],
                ]);
            }
            $changes[] = ['line' => $line, 'part' => $part, 'quantity' => $newQty, 'unit_cost' => $newCost, 'old_qty' => $oldQty];
            if ($part) {
                $deltas[$part->id] = ($deltas[$part->id] ?? 0) + ($newQty - $oldQty);
            }
        }

        foreach ($deltas as $partId => $delta) {
            if ($delta >= -0.0001) {
                continue;
            }
            $onHand = BranchInventory::partQty((int) $partId, $branchId);
            if ($onHand + 0.0001 < -$delta) {
                $name = $parts->get((int) $partId)?->name ?? 'This item';
                throw ValidationException::withMessages([
                    'items' => ["Only {$onHand} of {$name} is still on hand, so this GRN quantity cannot be reduced that far."],
                ]);
            }
        }

        foreach ($changes as $change) {
            /** @var StockReceiptItem $line */
            $line = $change['line'];
            /** @var Part|null $part */
            $part = $change['part'];
            $oldQty = $change['old_qty'];
            $oldCost = round((float) $line->unit_cost, 2);
            $newQty = $change['quantity'];
            $newCost = $change['unit_cost'];
            if ($part && (abs($newQty - $oldQty) > 0.001 || abs($newCost - $oldCost) > 0.001)) {
                $onHand = (float) $part->getRawOriginal('stock_qty');
                $part->forceFill([
                    'cost_price' => InventoryCosting::correctedCatalogueCost(
                        $method,
                        $onHand,
                        $part->cost_price,
                        $oldQty,
                        $oldCost,
                        $newQty,
                        $newCost,
                    ),
                ])->save();
                $delta = round($newQty - $oldQty, 3);
                if ($delta > 0.0001) {
                    BranchInventory::addPart($part, $delta, $branchId);
                } elseif ($delta < -0.0001) {
                    BranchInventory::takePart($part, -$delta, $branchId);
                }
                $part->refresh();
            }
            $line->update(['quantity' => $newQty, 'unit_cost' => $newCost]);
        }
    }

    private function syncExpenseTotal(StockReceipt $stockReceipt, string $status, ?string $dueDate): void
    {
        $stockReceipt->load('items');
        $total = round((float) $stockReceipt->items->sum(
            fn (StockReceiptItem $item) => (float) $item->quantity * (float) $item->unit_cost
        ), 2);
        $expense = $stockReceipt->expense()->first()
            ?? Expense::query()->where('stock_receipt_id', $stockReceipt->id)->first();

        if (! $expense && $total > 0) {
            $paid = $status !== Expense::STATUS_CREDIT;
            $date = $stockReceipt->received_at?->toDateString() ?? now()->toDateString();
            $label = $stockReceipt->invoice_number
                ? 'invoice '.$stockReceipt->invoice_number
                : $stockReceipt->receipt_number;
            $expense = Expense::create([
                'category' => 'inventory',
                'description' => ($paid ? 'Stock purchase: ' : 'Stock purchase on credit: ').$label,
                'amount' => $total,
                'expense_date' => $date,
                'payment_status' => $paid ? Expense::STATUS_PAID : Expense::STATUS_CREDIT,
                'due_date' => $paid ? null : $dueDate,
                'settled_at' => $paid ? $date : null,
                'created_by' => request()->user()?->id,
                'supplier_id' => $stockReceipt->supplier_id,
                'stock_receipt_id' => $stockReceipt->id,
                'branch_id' => $stockReceipt->branch_id,
            ]);
            $stockReceipt->update(['expense_id' => $expense->id]);

            return;
        }

        InventoryExpenseAmounts::assertCanApply($expense, $total, 'items');
        InventoryExpenseAmounts::apply($expense?->refresh(), $total);
    }

    private function descriptionWithInvoice(?string $description, ?string $from, string $to): ?string
    {
        $description = (string) $description;
        $from = trim((string) $from);
        if ($from !== '' && $from !== $to && str_contains($description, $from)) {
            return str_replace($from, $to, $description);
        }

        return $description;
    }
}
