<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\StockReceipt;
use App\Models\StockReceiptItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        ]);

        $invoice = trim($data['invoice_number']);
        $status = $data['payment_status'];
        $dueDate = $status === 'credit' ? $data['due_date'] : null;

        DB::transaction(function () use ($stockReceipt, $invoice, $status, $dueDate) {
            $expense = $stockReceipt->expense;
            $statusChanged = $status !== $stockReceipt->payment_status;
            if ($expense && $statusChanged) {
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
        });

        $stockReceipt->load([
            'supplier:id,name',
            'items' => fn ($query) => $query->where('item_type', 'part'),
            'items.part:id,name,sku,barcode,stock_unit',
        ]);

        return $this->moneyJson([
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
        ]);
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
