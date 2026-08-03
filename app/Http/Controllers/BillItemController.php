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
            'type' => ['required', Rule::in(['charge', 'part', 'labor', 'discount', 'customer_part'])],
            'part_id' => ['nullable', Rule::exists('parts', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'description' => ['nullable', 'string', 'max:255'],
            'quantity' => ['nullable', 'numeric', 'gt:0'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        if ($data['type'] === 'customer_part') {
            if (blank($data['description'] ?? null)) {
                throw ValidationException::withMessages(['description' => ['Describe the part received from the customer.']]);
            }
            $data['unit_price'] = 0;
            $data['part_id'] = null;
        }

        if ($data['type'] === 'part' && empty($data['part_id'])) {
            if (blank($data['description'] ?? null)) {
                throw ValidationException::withMessages(['description' => ['Describe the part bought outside.']]);
            }
            if (! array_key_exists('unit_price', $data) || $data['unit_price'] === null) {
                throw ValidationException::withMessages(['unit_price' => ['Enter the cost for a part bought outside.']]);
            }
        }

        if (in_array($data['type'], ['labor', 'charge', 'discount'], true) && (! array_key_exists('unit_price', $data) || $data['unit_price'] === null)) {
            throw ValidationException::withMessages(['unit_price' => ['The cost field is required.']]);
        }

        $quantity = (float) ($data['quantity'] ?? 1);
        if (in_array($data['type'], ['labor', 'charge', 'discount'], true)) {
            $quantity = 1;
        }

        if (in_array($data['type'], ['part', 'customer_part'], true) && $quantity !== (float) (int) $quantity) {
            throw ValidationException::withMessages(['quantity' => ['Parts must use a whole quantity.']]);
        }

        $item = DB::transaction(function () use ($data, $bill, $calculator, $quantity) {
            $part = ! empty($data['part_id']) ? Part::lockForUpdate()->findOrFail($data['part_id']) : null;
            if ($part && $part->stock_qty < $quantity) {
                throw ValidationException::withMessages(['quantity' => ['Insufficient stock for this part.']]);
            }

            $unitPrice = $data['type'] === 'customer_part'
                ? 0.0
                : (float) ($data['unit_price'] ?? $part?->price ?? 0);

            $item = $bill->items()->create([
                'part_id' => $part?->id,
                'type' => $data['type'],
                'description' => $data['description'] ?? $part?->name ?? ucfirst($data['type']),
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => round($unitPrice * $quantity, 2),
            ]);
            $part?->decrement('stock_qty', (int) $quantity);
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
