<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\BillItem;
use App\Models\BillRefund;
use App\Models\BillRefundItem;
use App\Support\BusinessTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BillRefundController extends Controller
{
    public function index(Bill $bill): JsonResponse
    {
        return $this->moneyJson(
            $bill->refunds()
                ->with(['items.billItem', 'creator:id,name'])
                ->get()
        );
    }

    public function store(Request $request, Bill $bill): JsonResponse
    {
        abort_unless($bill->isClosed(), 422, 'Only closed bills can be refunded.');

        $data = $request->validate([
            'refunded_at' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:1000'],
            'method' => ['required', Rule::in(BillRefund::METHODS)],
            'items' => ['required', 'array', 'min:1'],
            'items.*.bill_item_id' => ['required', 'integer'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'items.*.disposition' => ['nullable', Rule::in([
                BillRefundItem::DISPOSITION_RESTOCK,
                BillRefundItem::DISPOSITION_WRITE_OFF,
                BillRefundItem::DISPOSITION_NONE,
            ])],
        ]);

        $refund = DB::transaction(function () use ($request, $bill, $data) {
            /** @var Bill $locked */
            $locked = Bill::query()->lockForUpdate()->findOrFail($bill->id);
            abort_unless($locked->isClosed(), 422, 'Only closed bills can be refunded.');

            $itemIds = collect($data['items'])->pluck('bill_item_id')->map(fn ($id) => (int) $id)->all();
            $billItems = BillItem::query()
                ->where('bill_id', $locked->id)
                ->whereIn('id', $itemIds)
                ->with('part')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $alreadyRefunded = BillRefundItem::query()
                ->whereIn('bill_item_id', $itemIds)
                ->whereHas('refund', fn ($query) => $query->where('bill_id', $locked->id))
                ->selectRaw('bill_item_id, SUM(quantity) as qty, SUM(amount) as amount')
                ->groupBy('bill_item_id')
                ->get()
                ->keyBy('bill_item_id');

            $lines = [];
            $total = 0.0;

            foreach ($data['items'] as $index => $row) {
                $itemId = (int) $row['bill_item_id'];
                $item = $billItems->get($itemId);
                if (! $item) {
                    throw ValidationException::withMessages([
                        "items.{$index}.bill_item_id" => ['Item does not belong to this bill.'],
                    ]);
                }

                if (in_array($item->type, BusinessTypes::discountItemTypes(), true)) {
                    throw ValidationException::withMessages([
                        "items.{$index}.bill_item_id" => ['Discount lines cannot be refunded.'],
                    ]);
                }

                $quantity = round((float) $row['quantity'], 2);
                $priorQty = round((float) ($alreadyRefunded->get($itemId)?->qty ?? 0), 2);
                $remainingQty = round((float) $item->quantity - $priorQty, 2);
                if ($quantity > $remainingQty + 0.00001) {
                    throw ValidationException::withMessages([
                        "items.{$index}.quantity" => ["Only {$remainingQty} remaining to refund on this line."],
                    ]);
                }

                $kind = BusinessTypes::billItemKind($item->type);
                $canRestock = $item->type === 'part' && $item->part_id;
                $disposition = (string) ($row['disposition'] ?? BillRefundItem::DISPOSITION_NONE);

                if ($canRestock) {
                    if (! in_array($disposition, [BillRefundItem::DISPOSITION_RESTOCK, BillRefundItem::DISPOSITION_WRITE_OFF], true)) {
                        throw ValidationException::withMessages([
                            "items.{$index}.disposition" => ['Choose return to stock or write off for inventory items.'],
                        ]);
                    }
                } else {
                    $disposition = BillRefundItem::DISPOSITION_NONE;
                }

                if ($kind === 'stock' && $quantity !== (float) (int) $quantity) {
                    throw ValidationException::withMessages([
                        "items.{$index}.quantity" => ['Stock items must be refunded in whole quantities.'],
                    ]);
                }

                $unitPrice = (float) $item->unit_price;
                $amount = round($quantity * $unitPrice, 2);
                $total = round($total + $amount, 2);

                $lines[] = [
                    'item' => $item,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'amount' => $amount,
                    'disposition' => $disposition,
                ];
            }

            $refundableCash = round((float) $locked->amount_paid - (float) $locked->amount_refunded, 2);
            if ($total <= 0) {
                throw ValidationException::withMessages(['items' => ['Refund total must be greater than zero.']]);
            }
            if ($total > $refundableCash + 0.00001) {
                throw ValidationException::withMessages([
                    'items' => ["Refund total cannot exceed remaining paid amount ({$refundableCash})."],
                ]);
            }

            $refund = BillRefund::create([
                'bill_id' => $locked->id,
                'branch_id' => $locked->branch_id,
                'refunded_at' => $data['refunded_at'],
                'reason' => $data['reason'],
                'method' => $data['method'],
                'amount' => $total,
                'created_by' => $request->user()->id,
            ]);

            foreach ($lines as $line) {
                /** @var BillItem $item */
                $item = $line['item'];
                BillRefundItem::create([
                    'bill_refund_id' => $refund->id,
                    'bill_item_id' => $item->id,
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'amount' => $line['amount'],
                    'disposition' => $line['disposition'],
                ]);

                if ($line['disposition'] === BillRefundItem::DISPOSITION_RESTOCK && $item->part) {
                    $item->part->returnStock((float) $line['quantity'], $locked->branch_id);
                }
            }

            $locked->update([
                'amount_refunded' => round((float) $locked->amount_refunded + $total, 2),
                'updated_by' => $request->user()->id,
            ]);

            return $refund;
        });

        return $this->moneyJson(
            $refund->fresh()->load(['items.billItem', 'creator:id,name', 'bill']),
            201
        );
    }
}
