<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\BillItem;
use App\Models\Expense;
use App\Models\LaborItem;
use App\Models\Part;
use App\Models\ServiceAddon;
use App\Services\BillCalculator;
use App\Support\BusinessTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
            'labor_item_id' => ['nullable', Rule::exists('labor_items', 'id')->where('tenant_id', $request->user()->tenant_id)],
        ]);

        $data = ServiceAddon::applyToItemPayload($data, (int) $request->user()->tenant_id);
        $data = LaborItem::applyToItemPayload($data, (int) $request->user()->tenant_id);
        $this->assertLineReady($data, $typeMeta);

        $item = DB::transaction(function () use ($data, $bill, $calculator, $request) {
            $item = $this->createLine($bill, $data, $request);
            $calculator->recalculate($bill);

            return $item;
        });

        return response()->json(['item' => $item->load('part'), 'bill' => $bill->fresh()], 201);
    }

    public function storePanel(Request $request, Bill $bill, BillCalculator $calculator): JsonResponse
    {
        abort_if($bill->isLockedForEdits(), 422, $bill->isOweIn()
            ? 'Owe-in bills cannot be edited. Record a payment instead.'
            : 'Closed bills cannot be edited.');

        $businessType = (string) ($request->user()->tenant?->business_type ?? BusinessTypes::GARAGE);
        if ($businessType !== BusinessTypes::PAINT) {
            abort(422, 'Panel groups are only available for paint shops.');
        }

        $tenantId = (int) $request->user()->tenant_id;
        $data = $request->validate([
            'panel_name' => ['required', 'string', 'max:255'],
            'labor' => ['nullable', 'array'],
            'labor.*.labor_item_id' => ['nullable', Rule::exists('labor_items', 'id')->where('tenant_id', $tenantId)],
            'labor.*.description' => ['nullable', 'string', 'max:255'],
            'labor.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'labor.*.quantity' => ['required', 'numeric', 'gt:0'],
            'materials' => ['nullable', 'array'],
            'materials.*.part_id' => ['required', Rule::exists('parts', 'id')->where('tenant_id', $tenantId)],
            'materials.*.quantity' => ['required', 'numeric', 'gt:0'],
        ]);

        $labor = $data['labor'] ?? [];
        $materials = $data['materials'] ?? [];
        if ($labor === [] && $materials === []) {
            throw ValidationException::withMessages([
                'labor' => ['Add labor or materials for this panel.'],
            ]);
        }

        $typeMeta = collect(BusinessTypes::billItemTypes($businessType))->keyBy('value');
        $panelName = trim($data['panel_name']);
        $groupId = (string) Str::uuid();

        $items = DB::transaction(function () use ($labor, $materials, $bill, $calculator, $request, $tenantId, $typeMeta, $panelName, $groupId) {
            $created = [];

            foreach ($labor as $index => $row) {
                $payload = LaborItem::applyToItemPayload([
                    'type' => 'labor',
                    'labor_item_id' => $row['labor_item_id'] ?? null,
                    'description' => $row['description'] ?? null,
                    'unit_price' => $row['unit_price'] ?? null,
                    'quantity' => $row['quantity'],
                ], $tenantId);
                $payload['panel_group_id'] = $groupId;
                $payload['panel_name'] = $panelName;
                if (empty($payload['labor_item_id'])) {
                    if (blank($payload['description'] ?? null) || ! isset($payload['unit_price']) || $payload['unit_price'] === null || $payload['unit_price'] === '') {
                        throw ValidationException::withMessages([
                            "labor.$index.description" => ['Pick labor from the catalog, or enter a description and hourly rate.'],
                        ]);
                    }
                }
                $this->assertLineReady($payload, $typeMeta);
                $created[] = $this->createLine($bill, $payload, $request);
            }

            foreach ($materials as $row) {
                $payload = [
                    'type' => 'part',
                    'part_id' => $row['part_id'],
                    'quantity' => $row['quantity'],
                    'panel_group_id' => $groupId,
                    'panel_name' => $panelName,
                ];
                $this->assertLineReady($payload, $typeMeta);
                $created[] = $this->createLine($bill, $payload, $request);
            }

            $calculator->recalculate($bill);

            return $created;
        });

        return response()->json([
            'items' => collect($items)->map->load('part'),
            'bill' => $bill->fresh()->load(['customer', 'vehicle', 'items.part', 'payments']),
        ], 201);
    }

    public function update(Request $request, Bill $bill, BillItem $item, BillCalculator $calculator): JsonResponse
    {
        abort_unless($item->bill_id === $bill->id, 404);
        abort_if($bill->isLockedForEdits(), 422, $bill->isOweIn()
            ? 'Owe-in bills cannot be edited. Record a payment instead.'
            : 'Closed bills cannot be edited.');

        $data = $request->validate([
            'quantity' => ['sometimes', 'numeric', 'gt:0'],
            'unit_price' => ['sometimes', 'numeric', 'min:0'],
        ]);

        if ($item->type !== 'labor') {
            abort(422, 'Only labor lines can be updated on this bill.');
        }

        DB::transaction(function () use ($data, $bill, $item, $calculator) {
            $quantity = (float) ($data['quantity'] ?? $item->quantity);
            $unitPrice = (float) ($data['unit_price'] ?? $item->unit_price);
            $item->update([
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => round($unitPrice * $quantity, 2),
            ]);
            $calculator->recalculate($bill);
        });

        return response()->json(['item' => $item->fresh(), 'bill' => $bill->fresh()->load(['customer', 'vehicle', 'items.part', 'payments'])]);
    }

    public function destroy(Bill $bill, BillItem $item, BillCalculator $calculator): JsonResponse
    {
        abort_unless($item->bill_id === $bill->id, 404);
        abort_if($bill->isLockedForEdits(), 422, $bill->isOweIn()
            ? 'Owe-in bills cannot be edited. Record a payment instead.'
            : 'Closed bills cannot be edited.');

        DB::transaction(function () use ($bill, $item, $calculator) {
            $lines = $item->panel_group_id
                ? $bill->items()->where('panel_group_id', $item->panel_group_id)->get()
                : collect([$item]);

            foreach ($lines as $line) {
                if ($line->part_id) {
                    Part::whereKey($line->part_id)->increment('stock_qty', (int) $line->quantity);
                }
                if ($line->purchase_expense_id) {
                    Expense::whereKey($line->purchase_expense_id)->delete();
                }
                $line->delete();
            }
            $calculator->recalculate($bill);
        });

        return response()->json(['bill' => $bill->fresh()]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  \Illuminate\Support\Collection<string, array<string, mixed>>  $typeMeta
     */
    private function assertLineReady(array $data, $typeMeta): void
    {
        $kind = BusinessTypes::billItemKind($data['type']);
        $allowQty = (bool) ($typeMeta[$data['type']]['allow_qty'] ?? false)
            || $kind === 'stock'
            || $data['type'] === 'service_addon'
            || $data['type'] === 'labor';

        if ($data['type'] === 'customer_part') {
            if (blank($data['description'] ?? null)) {
                throw ValidationException::withMessages(['description' => ['Describe the part received from the customer.']]);
            }
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
        }

        if ($kind !== 'stock' && (! array_key_exists('unit_price', $data) || $data['unit_price'] === null || $data['unit_price'] === '') && $data['type'] !== 'customer_part') {
            if (empty($data['part_id']) && empty($data['labor_item_id'])) {
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
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createLine(Bill $bill, array $data, Request $request): BillItem
    {
        if ($data['type'] === 'customer_part') {
            $data['unit_price'] = 0;
            $data['part_id'] = null;
            $data['purchase_unit_cost'] = null;
        } elseif ($data['type'] !== 'part') {
            $data['purchase_unit_cost'] = null;
        }

        $kind = BusinessTypes::billItemKind($data['type']);
        $typeMeta = collect(BusinessTypes::billItemTypes(
            (string) ($request->user()->tenant?->business_type ?? BusinessTypes::GARAGE)
        ))->keyBy('value');
        $allowQty = (bool) ($typeMeta[$data['type']]['allow_qty'] ?? false)
            || $kind === 'stock'
            || $data['type'] === 'service_addon'
            || $data['type'] === 'labor';

        $quantity = (float) ($data['quantity'] ?? 1);
        if (! $allowQty) {
            $quantity = 1;
        }

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
            'labor_item_id' => $data['labor_item_id'] ?? null,
            'panel_group_id' => $data['panel_group_id'] ?? null,
            'panel_name' => $data['panel_name'] ?? null,
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

        return $item;
    }
}
