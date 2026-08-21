<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\BillItem;
use App\Models\Expense;
use App\Models\Part;
use App\Models\ServiceAddon;
use App\Services\BillCalculator;
use App\Support\BusinessTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BillItemController extends Controller
{
    public function store(Request $request, Bill $bill, BillCalculator $calculator): JsonResponse
    {
        abort_if($bill->isLockedForEdits(), 422, $bill->isOweIn()
            ? 'Owe-in bills cannot be edited. Record a payment instead.'
            : 'Closed bills cannot be edited.');

        $businessType = $request->user()->tenant?->business_type ?? BusinessTypes::GARAGE;
        $allowed = BusinessTypes::allowedBillItemTypes($businessType);
        $typeMeta = collect(BusinessTypes::billItemTypes($businessType))->keyBy('value');

        $data = $request->validate([
            'type' => ['required', Rule::in($allowed)],
            'part_id' => ['nullable', Rule::exists('parts', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'description' => ['nullable', 'string', 'max:255'],
            'quantity' => ['nullable', 'numeric', 'gt:0'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
            'purchase_unit_cost' => ['nullable', 'numeric', 'min:0'],
            'service_addon_id' => ['nullable', Rule::exists('service_addons', 'id')->where('tenant_id', $request->user()->tenant_id)],
        ]);

        $data = ServiceAddon::applyToItemPayload($data, (int) $request->user()->tenant_id);

        $kind = BusinessTypes::billItemKind($data['type']);
        $allowQty = (bool) ($typeMeta[$data['type']]['allow_qty'] ?? false)
            || $kind === 'stock'
            || $data['type'] === 'service_addon';

        if ($data['type'] === 'customer_part') {
            if (blank($data['description'] ?? null)) {
                throw ValidationException::withMessages(['description' => ['Describe the part received from the customer.']]);
            }
            $data['unit_price'] = 0;
            $data['part_id'] = null;
            $data['purchase_unit_cost'] = null;
        }

        if ($data['type'] === 'part' && empty($data['part_id'])) {
            if (blank($data['description'] ?? null)) {
                throw ValidationException::withMessages(['description' => ['Describe the part bought outside.']]);
            }
            if (! array_key_exists('unit_price', $data) || $data['unit_price'] === null) {
                throw ValidationException::withMessages(['unit_price' => ['Enter the selling price for a part bought outside.']]);
            }
            if (! array_key_exists('purchase_unit_cost', $data) || $data['purchase_unit_cost'] === null) {
                throw ValidationException::withMessages(['purchase_unit_cost' => ['Enter the purchase cost for a part bought outside.']]);
            }
        } elseif ($data['type'] !== 'part') {
            $data['purchase_unit_cost'] = null;
        }

        if ($kind !== 'stock' && (! array_key_exists('unit_price', $data) || $data['unit_price'] === null) && $data['type'] !== 'customer_part') {
            if (empty($data['part_id'])) {
                throw ValidationException::withMessages(['unit_price' => ['The cost field is required.']]);
            }
        }

        $quantity = (float) ($data['quantity'] ?? 1);
        if (! $allowQty) {
            $quantity = 1;
        }

        if ($kind === 'stock' && $quantity !== (float) (int) $quantity) {
            throw ValidationException::withMessages(['quantity' => ['Stock lines must use a whole quantity.']]);
        }

        $item = DB::transaction(function () use ($data, $bill, $calculator, $quantity, $request) {
            $part = ! empty($data['part_id']) ? Part::lockForUpdate()->findOrFail($data['part_id']) : null;
            if ($part && $part->stock_qty < $quantity) {
                throw ValidationException::withMessages(['quantity' => ['Insufficient stock for this part.']]);
            }

            $unitPrice = $data['type'] === 'customer_part'
                ? 0.0
                : (float) ($data['unit_price'] ?? $part?->price ?? 0);

            $purchaseUnitCost = $part
                ? (float) $part->cost_price
                : (isset($data['purchase_unit_cost']) && $data['purchase_unit_cost'] !== null
                    ? (float) $data['purchase_unit_cost']
                    : null);

            $expense = null;
            if ($data['type'] === 'part' && ! $part && $purchaseUnitCost !== null && $purchaseUnitCost > 0 && $quantity > 0) {
                $expense = Expense::create([
                    'category' => 'inventory',
                    'description' => 'Outside part: '.($data['description'] ?? 'Bought outside').' × '.(int) $quantity,
                    'amount' => round($purchaseUnitCost * $quantity, 2),
                    'expense_date' => now()->toDateString(),
                    'payment_status' => Expense::STATUS_PAID,
                    'settled_at' => now(),
                    'created_by' => $request->user()->id,
                ]);
            }

            $item = $bill->items()->create([
                'part_id' => $part?->id,
                'service_addon_id' => $data['service_addon_id'] ?? null,
                'type' => $data['type'],
                'description' => $data['description'] ?? $part?->name ?? BusinessTypes::billItemLabel($data['type']),
                'included_services' => $data['included_services'] ?? null,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'purchase_unit_cost' => $purchaseUnitCost,
                'purchase_expense_id' => $expense?->id,
                'line_total' => round($unitPrice * $quantity, 2),
            ]);
            $part?->takeStock((int) $quantity);
            $calculator->recalculate($bill);

            return $item;
        });

        return response()->json(['item' => $item->load('part'), 'bill' => $bill->fresh()], 201);
    }

    public function destroy(Bill $bill, BillItem $item, BillCalculator $calculator): JsonResponse
    {
        abort_unless($item->bill_id === $bill->id, 404);
        abort_if($bill->isLockedForEdits(), 422, $bill->isOweIn()
            ? 'Owe-in bills cannot be edited. Record a payment instead.'
            : 'Closed bills cannot be edited.');

        DB::transaction(function () use ($bill, $item, $calculator) {
            if ($item->part_id) {
                Part::whereKey($item->part_id)->increment('stock_qty', (int) $item->quantity);
            }
            if ($item->purchase_expense_id) {
                Expense::whereKey($item->purchase_expense_id)->delete();
            }
            $item->delete();
            $calculator->recalculate($bill);
        });

        return response()->json(['bill' => $bill->fresh()]);
    }
}
