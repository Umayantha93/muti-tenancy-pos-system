<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Bill;
use App\Models\BillItem;
use App\Models\BillPayment;
use App\Models\CottageStay;
use App\Models\Expense;
use App\Models\LaborItem;
use App\Models\Part;
use App\Models\PhotoBooking;
use App\Models\RetailSale;
use App\Models\ServiceAddon;
use App\Models\Tenant;
use App\Services\BillCalculator;
use App\Support\BusinessTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SuperAdminBillController extends Controller
{
    public function index(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:50'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $bills = Bill::query()
            ->where('tenant_id', $tenant->id)
            ->with(['customer', 'vehicle', 'employees:id,name,position'])
            ->when(! empty($data['status']), fn ($query) => $query->where('status', $data['status']))
            ->when(! empty($data['search']), function ($query) use ($data) {
                $search = '%'.$data['search'].'%';
                $query->where(fn ($nested) => $nested->where('bill_number', 'like', $search)
                    ->orWhereHas('vehicle', fn ($vehicle) => $vehicle->where('number_plate', 'like', $search))
                    ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', $search)));
            })
            ->latest('admission_date')
            ->orderByDesc('id')
            ->paginate($data['per_page'] ?? 30);

        return $this->moneyJson($bills);
    }

    public function show(Tenant $tenant, Bill $bill): JsonResponse
    {
        $this->assertBillTenant($tenant, $bill);

        return $this->moneyJson($bill->load(['customer', 'vehicle', 'items.part', 'payments.receiver', 'creator', 'employees:id,name,position']));
    }

    public function update(Request $request, Tenant $tenant, Bill $bill): JsonResponse
    {
        $this->assertBillTenant($tenant, $bill);

        $data = $request->validate([
            'notes' => ['nullable', 'string'],
            'internal_notes' => ['nullable', 'string'],
            'odometer' => ['nullable', 'integer', 'min:0'],
            'mileage' => ['nullable', 'integer', 'min:0'],
        ]);
        $bill->update([...$data, 'updated_by' => $request->user()->id]);

        return $this->moneyJson($bill->fresh()->load(['customer', 'vehicle', 'items.part', 'payments.receiver', 'creator', 'employees:id,name,position']));
    }

    public function reopen(Request $request, Tenant $tenant, Bill $bill, BillCalculator $calculator): JsonResponse
    {
        $this->assertBillTenant($tenant, $bill);
        abort_unless($bill->isClosed(), 422, 'This bill is not closed.');

        $bill->update([
            'status' => 'open',
            'closed_at' => null,
            'owe_in_due_date' => null,
            'updated_by' => $request->user()->id,
        ]);
        $calculator->recalculate($bill);
        $this->audit($request, $tenant, $bill, 'bill.reopened');

        return $this->moneyJson($bill->fresh()->load(['customer', 'vehicle', 'items.part', 'payments.receiver', 'creator']));
    }

    public function close(Request $request, Tenant $tenant, Bill $bill, BillCalculator $calculator): JsonResponse
    {
        $this->assertBillTenant($tenant, $bill);
        abort_if($bill->isClosed(), 422, 'This bill is already closed.');

        $calculator->recalculate($bill);
        $bill->update([
            'status' => 'closed',
            'closed_at' => $bill->closed_at ?? now(),
            'updated_by' => $request->user()->id,
        ]);
        $this->audit($request, $tenant, $bill, 'bill.closed');

        return $this->moneyJson($bill->fresh()->load(['customer', 'vehicle', 'items.part', 'payments.receiver', 'creator']));
    }

    public function destroy(Request $request, Tenant $tenant, Bill $bill): JsonResponse
    {
        $this->assertBillTenant($tenant, $bill);
        $billNumber = $bill->bill_number;

        DB::transaction(function () use ($bill) {
            $bill->load('items');
            foreach ($bill->items as $item) {
                if ($item->part_id) {
                    Part::whereKey($item->part_id)->increment('stock_qty', (int) $item->quantity);
                }
                if ($item->purchase_expense_id) {
                    Expense::whereKey($item->purchase_expense_id)->delete();
                }
            }
            PhotoBooking::where('bill_id', $bill->id)->update(['bill_id' => null]);
            RetailSale::where('bill_id', $bill->id)->update(['bill_id' => null]);
            CottageStay::where('bill_id', $bill->id)->update(['bill_id' => null]);
            $bill->payments()->delete();
            $bill->items()->delete();
            $bill->delete();
        });

        $this->audit($request, $tenant, null, 'bill.deleted', [
            'bill_id' => $bill->id,
            'bill_number' => $billNumber,
        ]);

        return response()->json(['message' => 'Bill deleted.']);
    }

    public function storeItem(Request $request, Tenant $tenant, Bill $bill, BillCalculator $calculator): JsonResponse
    {
        $this->assertBillTenant($tenant, $bill);

        $businessType = $tenant->business_type ?? BusinessTypes::GARAGE;
        $allowed = BusinessTypes::allowedBillItemTypes($businessType);
        $typeMeta = collect(BusinessTypes::billItemTypes($businessType))->keyBy('value');

        $data = $request->validate([
            'type' => ['required', Rule::in($allowed)],
            'part_id' => ['nullable', Rule::exists('parts', 'id')->where('tenant_id', $tenant->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'quantity' => ['nullable', 'numeric', 'gt:0'],
            'unit_price' => ['nullable', 'numeric', 'min:0'],
            'purchase_unit_cost' => ['nullable', 'numeric', 'min:0'],
            'service_addon_id' => ['nullable', Rule::exists('service_addons', 'id')->where('tenant_id', $tenant->id)],
            'labor_item_id' => ['nullable', Rule::exists('labor_items', 'id')->where('tenant_id', $tenant->id)],
        ]);

        $data = ServiceAddon::applyToItemPayload($data, (int) $tenant->id);
        $data = LaborItem::applyToItemPayload($data, (int) $tenant->id);

        $kind = BusinessTypes::billItemKind($data['type']);
        $allowQty = (bool) ($typeMeta[$data['type']]['allow_qty'] ?? false)
            || $kind === 'stock'
            || $data['type'] === 'service_addon'
            || $data['type'] === 'labor';

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

        DB::transaction(function () use ($data, $bill, $calculator, $quantity, $request) {
            $part = ! empty($data['part_id'])
                ? Part::where('tenant_id', $bill->tenant_id)->lockForUpdate()->findOrFail($data['part_id'])
                : null;
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
                $expense = new Expense;
                $expense->forceFill([
                    'category' => 'inventory',
                    'description' => 'Outside part: '.($data['description'] ?? 'Bought outside').' × '.(int) $quantity,
                    'amount' => round($purchaseUnitCost * $quantity, 2),
                    'expense_date' => now()->toDateString(),
                    'payment_status' => Expense::STATUS_PAID,
                    'settled_at' => now(),
                    'created_by' => $request->user()->id,
                ]);
                $expense->tenant_id = $bill->tenant_id;
                $expense->save();
            }

            $item = $bill->items()->make([
                'part_id' => $part?->id,
                'service_addon_id' => $data['service_addon_id'] ?? null,
                'labor_item_id' => $data['labor_item_id'] ?? null,
                'type' => $data['type'],
                'description' => $data['description'] ?? $part?->name ?? BusinessTypes::billItemLabel($data['type']),
                'included_services' => $data['included_services'] ?? null,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'purchase_unit_cost' => $purchaseUnitCost,
                'purchase_expense_id' => $expense?->id,
                'line_total' => round($unitPrice * $quantity, 2),
            ]);
            $item->tenant_id = $bill->tenant_id;
            $item->save();
            $part?->takeStock((int) $quantity);
            $calculator->recalculate($bill);

            return $item;
        });

        $bill->update(['updated_by' => $request->user()->id]);

        return $this->moneyJson($bill->fresh()->load(['customer', 'vehicle', 'items.part', 'payments.receiver', 'creator']), 201);
    }

    public function updateItem(Request $request, Tenant $tenant, Bill $bill, BillItem $item, BillCalculator $calculator): JsonResponse
    {
        $this->assertBillTenant($tenant, $bill);
        abort_unless($item->bill_id === $bill->id && $item->tenant_id === $tenant->id, 404);

        $data = $request->validate([
            'description' => ['sometimes', 'string', 'max:255'],
            'quantity' => ['sometimes', 'numeric', 'gt:0'],
            'unit_price' => ['sometimes', 'numeric', 'min:0'],
        ]);

        DB::transaction(function () use ($data, $bill, $item, $calculator) {
            if (array_key_exists('quantity', $data) && $item->part_id) {
                $newQty = (float) $data['quantity'];
                if ($newQty !== (float) (int) $newQty) {
                    throw ValidationException::withMessages(['quantity' => ['Stock lines must use a whole quantity.']]);
                }
                $delta = (int) $newQty - (int) $item->quantity;
                if ($delta > 0) {
                    $part = Part::where('tenant_id', $bill->tenant_id)->lockForUpdate()->findOrFail($item->part_id);
                    if ($part->stock_qty < $delta) {
                        throw ValidationException::withMessages(['quantity' => ['Insufficient stock for this part.']]);
                    }
                    $part->decrement('stock_qty', $delta);
                } elseif ($delta < 0) {
                    Part::whereKey($item->part_id)->increment('stock_qty', abs($delta));
                }
            }

            $quantity = (float) ($data['quantity'] ?? $item->quantity);
            $unitPrice = $item->type === 'customer_part'
                ? 0.0
                : (float) ($data['unit_price'] ?? $item->unit_price);

            $item->update([
                'description' => $data['description'] ?? $item->description,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => round($unitPrice * $quantity, 2),
            ]);
            $calculator->recalculate($bill);
        });

        $bill->update(['updated_by' => $request->user()->id]);

        return $this->moneyJson($bill->fresh()->load(['customer', 'vehicle', 'items.part', 'payments.receiver', 'creator']));
    }

    public function destroyItem(Request $request, Tenant $tenant, Bill $bill, BillItem $item, BillCalculator $calculator): JsonResponse
    {
        $this->assertBillTenant($tenant, $bill);
        abort_unless($item->bill_id === $bill->id && $item->tenant_id === $tenant->id, 404);

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

        $bill->update(['updated_by' => $request->user()->id]);

        return $this->moneyJson($bill->fresh()->load(['customer', 'vehicle', 'items.part', 'payments.receiver', 'creator']));
    }

    public function storePayment(Request $request, Tenant $tenant, Bill $bill, BillCalculator $calculator): JsonResponse
    {
        $this->assertBillTenant($tenant, $bill);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', Rule::in(['cash', 'card', 'bank_transfer', 'other'])],
            'reference' => ['nullable', 'string', 'max:100'],
            'paid_at' => ['nullable', 'date'],
        ]);

        DB::transaction(function () use ($data, $bill, $request, $calculator) {
            $payment = $bill->payments()->make([
                ...$data,
                'paid_at' => $data['paid_at'] ?? now(),
                'received_by' => $request->user()->id,
            ]);
            $payment->tenant_id = $bill->tenant_id;
            $payment->save();
            $calculator->recalculate($bill);
        });

        $bill->update(['updated_by' => $request->user()->id]);

        return $this->moneyJson($bill->fresh()->load(['customer', 'vehicle', 'items.part', 'payments.receiver', 'creator']), 201);
    }

    public function destroyPayment(Request $request, Tenant $tenant, Bill $bill, BillPayment $payment, BillCalculator $calculator): JsonResponse
    {
        $this->assertBillTenant($tenant, $bill);
        abort_unless($payment->bill_id === $bill->id && $payment->tenant_id === $tenant->id, 404);

        DB::transaction(function () use ($bill, $payment, $calculator) {
            $payment->delete();
            $calculator->recalculate($bill);
        });

        $bill->update(['updated_by' => $request->user()->id]);

        return $this->moneyJson($bill->fresh()->load(['customer', 'vehicle', 'items.part', 'payments.receiver', 'creator']));
    }

    public function parts(Request $request, Tenant $tenant): JsonResponse
    {
        $search = $request->string('search')->toString();

        $parts = Part::query()
            ->where('tenant_id', $tenant->id)
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.$search.'%';
                $query->where(fn ($nested) => $nested
                    ->where('name', 'like', $like)
                    ->orWhere('sku', 'like', $like)
                    ->orWhere('brand', 'like', $like));
            })
            ->orderBy('name')
            ->limit(50)
            ->get(['id', 'name', 'price', 'stock_qty', 'sku', 'brand']);

        return $this->moneyJson($parts);
    }

    private function assertBillTenant(Tenant $tenant, Bill $bill): void
    {
        abort_unless($bill->tenant_id === $tenant->id, 404);
    }

    private function audit(Request $request, Tenant $tenant, ?Bill $bill, string $action, array $metadata = []): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'tenant_id' => $tenant->id,
            'action' => $action,
            'subject_type' => Bill::class,
            'subject_id' => $bill?->id ?? ($metadata['bill_id'] ?? null),
            'metadata' => array_filter([
                'bill_number' => $bill?->bill_number,
                ...$metadata,
            ]),
            'ip_address' => $request->ip(),
        ]);
    }
}
