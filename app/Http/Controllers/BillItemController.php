<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\BillItem;
use App\Models\Part;
use App\Services\BillCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BillItemController extends Controller
{
    public function store(Request $request, Bill $bill, BillCalculator $calculator): JsonResponse
    {
        abort_if($bill->status === 'closed', 422, 'Closed bills cannot be edited.');
        $data = $request->validate([
            'type' => ['required', Rule::in(['charge', 'part', 'labor', 'advance', 'discount'])],
            'part_id' => ['nullable', 'required_if:type,part', Rule::exists('parts', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'description' => ['nullable', 'string', 'max:255'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'unit_price' => ['nullable', 'required_unless:type,part', 'numeric', 'min:0'],
        ]);

        if ($data['type'] === 'part' && (float) $data['quantity'] !== (float) (int) $data['quantity']) {
            throw ValidationException::withMessages(['quantity' => ['Inventory parts must use a whole quantity.']]);
        }

        $item = DB::transaction(function () use ($data, $bill, $calculator) {
            $part = isset($data['part_id']) ? Part::lockForUpdate()->findOrFail($data['part_id']) : null;
            if ($part && $part->stock_qty < $data['quantity']) {
                throw ValidationException::withMessages(['quantity' => ['Insufficient stock for this part.']]);
            }

            $unitPrice = (float) ($data['unit_price'] ?? $part->price);
            $item = $bill->items()->create([
                'part_id' => $part?->id,
                'type' => $data['type'],
                'description' => $data['description'] ?? $part?->name ?? ucfirst($data['type']),
                'quantity' => $data['quantity'],
                'unit_price' => $unitPrice,
                'line_total' => round($unitPrice * (float) $data['quantity'], 2),
            ]);
            $part?->decrement('stock_qty', (int) $data['quantity']);
            $calculator->recalculate($bill);

            return $item;
        });

        return response()->json(['item' => $item->load('part'), 'bill' => $bill->fresh()], 201);
    }

    public function destroy(Bill $bill, BillItem $item, BillCalculator $calculator): JsonResponse
    {
        abort_unless($item->bill_id === $bill->id, 404);
        abort_if($bill->status === 'closed', 422, 'Closed bills cannot be edited.');

        DB::transaction(function () use ($bill, $item, $calculator) {
            if ($item->part_id) {
                Part::whereKey($item->part_id)->increment('stock_qty', (int) $item->quantity);
            }
            $item->delete();
            $calculator->recalculate($bill);
        });

        return response()->json(['bill' => $bill->fresh()]);
    }
}
