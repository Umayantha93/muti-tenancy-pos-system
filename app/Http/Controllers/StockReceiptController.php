<?php

namespace App\Http\Controllers;

use App\Models\StockReceipt;
use App\Models\StockReceiptItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
}
