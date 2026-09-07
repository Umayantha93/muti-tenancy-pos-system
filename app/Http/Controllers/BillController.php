<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\BillItem;
use App\Models\BillPayment;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Vehicle;
use App\Services\BillCalculator;
use App\Support\BranchQuery;
use App\Support\BusinessTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BillController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:50'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'job_kind' => ['nullable', Rule::in([Bill::JOB_KIND_SERVICE, Bill::JOB_KIND_REPAIR, Bill::JOB_KIND_PARTS_SALE])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $bills = Bill::query()
            ->with(['customer', 'vehicle', 'items', 'payments', 'employees:id,name,position', 'branch:id,name,code,address,phone'])
            ->when(! empty($data['status']), fn ($query) => $query->where('status', $data['status']))
            ->when(! empty($data['job_kind']), fn ($query) => $query->where('job_kind', $data['job_kind']))
            ->when(! empty($data['search']), function ($query) use ($data) {
                $search = '%'.$data['search'].'%';
                $query->where(fn ($nested) => $nested->where('bill_number', 'like', $search)
                    ->orWhere('notes', 'like', $search)
                    ->orWhereHas('vehicle', fn ($vehicle) => $vehicle->where('number_plate', 'like', $search))
                    ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', $search)->orWhere('phone', 'like', $search)));
            })
            ->when(! empty($data['date_from']), fn ($query) => $query->whereDate('admission_date', '>=', $data['date_from']))
            ->when(! empty($data['date_to']), fn ($query) => $query->whereDate('admission_date', '<=', $data['date_to']))
            ->queued()
            ->tap(fn ($query) => BranchQuery::constrain($query))
            ->paginate($data['per_page'] ?? 15);

        return $this->moneyJson($bills);
    }

    public function store(Request $request): JsonResponse
    {
        $type = BusinessTypes::normalizeLegacy((string) ($request->user()->tenant?->business_type ?? BusinessTypes::GARAGE));

        if (! BusinessTypes::usesVehicleJobs($type)) {
            return $this->storeGeneric($request, $type);
        }

        $data = $request->validate([
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'regex:/^[0-9+() -]{7,20}$/'],
            'customer_address' => ['nullable', 'string', 'max:255'],
            'number_plate' => ['required', 'string', 'max:30'],
            'chassis_number' => ['nullable', 'string', 'max:100'],
            'make' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'year' => ['nullable', 'integer', 'between:1900,'.(now()->year + 1)],
            'odometer' => ['nullable', 'integer', 'min:0'],
            'mileage' => ['nullable', 'integer', 'min:0'],
            'next_service_mileage' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
            'internal_notes' => ['nullable', 'string'],
            'additional_note_color' => ['nullable', Rule::in(['blue', 'red'])],
            'admission_date' => ['nullable', 'date'],
            'job_kind' => ['nullable', Rule::in([Bill::JOB_KIND_REPAIR, Bill::JOB_KIND_SERVICE])],
            'tyre_size' => ['nullable', 'string', 'max:40'],
            'axle' => ['nullable', 'string', 'max:40'],
            'imei' => ['nullable', 'string', 'max:40'],
            'fault_description' => ['nullable', 'string', 'max:255'],
            'asset_kind' => ['nullable', Rule::in(['vehicle', 'device'])],
            ...$this->employeeIdsRules($request),
        ]);

        $bill = DB::transaction(function () use ($data, $request) {
            $customer = Customer::resolveFromIntake(
                $data['customer_name'] ?? null,
                $data['customer_phone'] ?? null,
                $data['customer_address'] ?? null,
            );

            $chassisRaw = isset($data['chassis_number']) ? strtoupper(trim((string) $data['chassis_number'])) : '';
            $chassis = $chassisRaw !== '' ? $chassisRaw : null;
            $plate = strtoupper($data['number_plate']);
            $vehicleAttrs = [
                'customer_id' => $customer->id,
                'number_plate' => $plate,
                'make' => $data['make'] ?? null,
                'model' => $data['model'] ?? null,
                'year' => $data['year'] ?? null,
                'asset_kind' => $data['asset_kind'] ?? 'vehicle',
                'imei' => $data['imei'] ?? null,
                'tyre_size' => $data['tyre_size'] ?? null,
                'axle' => $data['axle'] ?? null,
                'fault_description' => $data['fault_description'] ?? null,
            ];

            if ($chassis) {
                $vehicle = Vehicle::updateOrCreate(
                    ['chassis_number' => $chassis],
                    $vehicleAttrs,
                );
            } else {
                $vehicle = Vehicle::query()
                    ->where('number_plate', $plate)
                    ->where('customer_id', $customer->id)
                    ->first();

                if ($vehicle) {
                    $vehicle->update($vehicleAttrs);
                } else {
                    $vehicle = Vehicle::create([...$vehicleAttrs, 'chassis_number' => null]);
                }
            }

            return $this->openBill($request, $customer->id, $data, $this->jobType($request), $vehicle->id);
        });

        return response()->json($bill, 201);
    }

    public function storeFromVehicle(Request $request): JsonResponse
    {
        $data = $request->validate([
            'vehicle_id' => ['required', Rule::exists('vehicles', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'odometer' => ['nullable', 'integer', 'min:0'],
            'mileage' => ['nullable', 'integer', 'min:0'],
            'next_service_mileage' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
            'internal_notes' => ['nullable', 'string'],
            'additional_note_color' => ['nullable', Rule::in(['blue', 'red'])],
            'admission_date' => ['nullable', 'date'],
            'job_kind' => ['nullable', Rule::in([Bill::JOB_KIND_REPAIR, Bill::JOB_KIND_SERVICE])],
            ...$this->employeeIdsRules($request),
        ]);

        $vehicle = Vehicle::with('customer')->findOrFail($data['vehicle_id']);
        $bill = $this->openBill($request, $vehicle->customer_id, $data, $this->jobType($request), $vehicle->id);

        return response()->json($bill, 201);
    }

    /**
     * Walk-in / counter bill: same billing workspace as a job card, without registering a vehicle.
     */
    public function storeInstant(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'regex:/^[0-9+() -]{7,20}$/'],
            'customer_address' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'internal_notes' => ['nullable', 'string'],
            'additional_note_color' => ['nullable', Rule::in(['blue', 'red'])],
            'admission_date' => ['nullable', 'date'],
            ...$this->employeeIdsRules($request),
        ]);

        $bill = DB::transaction(function () use ($data, $request) {
            $customer = Customer::resolveFromIntake(
                $data['customer_name'] ?? null,
                $data['customer_phone'] ?? null,
                $data['customer_address'] ?? null,
            );

            $bill = Bill::create([
                'bill_number' => 'INST-'.now()->format('Ymd').'-'.strtoupper(str()->random(6)),
                'vehicle_id' => null,
                'customer_id' => $customer->id,
                'admission_date' => $data['admission_date'] ?? today(),
                'notes' => $data['notes'] ?? null,
                'internal_notes' => $data['internal_notes'] ?? null,
            'additional_note_color' => $data['additional_note_color'] ?? null,
                'job_kind' => Bill::JOB_KIND_PARTS_SALE,
                'created_by' => $request->user()->id,
            ]);
            $this->syncBillEmployees($bill, $data['employee_ids'] ?? []);

            return $bill->load(['customer', 'vehicle', 'items', 'payments', 'employees:id,name,position']);
        });

        return response()->json($bill, 201);
    }

    public function storeQuick(Request $request, BillCalculator $calculator): JsonResponse
    {
        $type = BusinessTypes::normalizeLegacy((string) ($request->user()->tenant?->business_type ?? BusinessTypes::GARAGE));
        abort_unless(BusinessTypes::usesStoreCounter($type), 422, 'Quick bills are for store tenants.');

        $data = $request->validate([
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0'],
            'quantity' => ['nullable', 'numeric', 'gt:0'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'regex:/^[0-9+() -]{7,20}$/'],
            'payment_method' => ['nullable', Rule::in(['cash', 'card', 'bank_transfer', 'other'])],
            'payment_amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $bill = DB::transaction(function () use ($data, $request, $calculator) {
            $name = trim((string) ($data['customer_name'] ?? ''));
            $phone = trim((string) ($data['customer_phone'] ?? ''));
            if ($phone !== '') {
                $customer = Customer::resolveFromIntake($name !== '' ? $name : null, $phone);
            } elseif ($name !== '') {
                $customer = Customer::resolveFromIntake($name, null);
            } else {
                $customer = Customer::firstOrCreate(
                    ['phone' => '0000000000'],
                    ['name' => 'Walk-in customer'],
                );
            }

            $quantity = (float) ($data['quantity'] ?? 1);
            $unitPrice = (float) $data['amount'];
            $lineTotal = round($unitPrice * $quantity, 2);

            $bill = Bill::create([
                'bill_number' => 'QCK-'.now()->format('Ymd').'-'.strtoupper(str()->random(6)),
                'customer_id' => $customer->id,
                'admission_date' => today(),
                'notes' => $data['description'],
                'job_kind' => Bill::JOB_KIND_PARTS_SALE,
                'created_by' => $request->user()->id,
            ]);

            BillItem::create([
                'bill_id' => $bill->id,
                'type' => 'charge',
                'description' => $data['description'],
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
            ]);
            $calculator->recalculate($bill);

            $payAmount = array_key_exists('payment_amount', $data)
                ? (float) $data['payment_amount']
                : $lineTotal;
            if ($payAmount > 0) {
                BillPayment::create([
                    'bill_id' => $bill->id,
                    'amount' => $payAmount,
                    'method' => $data['payment_method'] ?? 'cash',
                    'paid_at' => now(),
                    'received_by' => $request->user()->id,
                ]);
                $calculator->recalculate($bill->fresh());
            }

            return $bill->fresh()->load(['customer', 'items', 'payments']);
        });

        return $this->moneyJson($bill, 201);
    }

    public function show(Bill $bill): JsonResponse
    {
        $bill->ensureShareToken();

        return $this->moneyJson($bill->load(['customer', 'vehicle', 'items.part', 'payments.receiver', 'creator', 'employees:id,name,position', 'branch:id,name,code,address,phone']));
    }

    public function update(Request $request, Bill $bill): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['open', 'partially_paid', 'paid', 'closed', 'owe_in'])],
            'notes' => ['nullable', 'string'],
            'internal_notes' => ['nullable', 'string'],
            'additional_note_color' => ['nullable', Rule::in(['blue', 'red'])],
            'odometer' => ['nullable', 'integer', 'min:0'],
            'mileage' => ['nullable', 'integer', 'min:0'],
            'next_service_mileage' => ['nullable', 'integer', 'min:0'],
            'hide_amounts' => ['sometimes', 'boolean'],
            ...$this->employeeIdsRules($request),
        ]);

        $employeeIds = $data['employee_ids'] ?? null;
        unset($data['employee_ids']);

        if (array_key_exists('hide_amounts', $data)) {
            $isGarage = $request->user()->tenant?->business_type === BusinessTypes::GARAGE;
            abort_unless($isGarage, 422, 'Repair notes are only available on garage jobs.');
            if ($data['hide_amounts'] && (float) $bill->amount_paid > 0) {
                abort(422, 'Amounts cannot be hidden after a payment is recorded.');
            }
        }

        $staffOnly = collect($data)->except(['notes', 'internal_notes', 'additional_note_color', 'hide_amounts'])->isEmpty();
        if (! $staffOnly) {
            abort_if($bill->isLockedForEdits(), 422, $bill->isOweIn()
                ? 'Owe-in bills cannot be edited. Record a payment instead.'
                : 'Closed bills cannot be edited.');
        }

        if (($data['status'] ?? null) === 'closed' && ! $this->isPaidBill($bill)) {
            abort(422, 'Only paid bills can be closed.');
        }
        if (($data['status'] ?? null) === 'owe_in') {
            abort(422, 'Use the Owe In action to mark a bill on credit.');
        }
        $bill->update([...$data, 'updated_by' => $request->user()->id]);
        if ($employeeIds !== null) {
            $this->syncBillEmployees($bill, $employeeIds);
        }

        return response()->json($bill->refresh()->load(['customer', 'vehicle', 'items.part', 'payments', 'employees:id,name,position']));
    }

    public function syncEmployees(Request $request, Bill $bill): JsonResponse
    {
        $data = $request->validate($this->employeeIdsRules($request));
        $this->syncBillEmployees($bill, $data['employee_ids'] ?? []);

        return $this->moneyJson($bill->fresh()->load(['customer', 'vehicle', 'items.part', 'payments.receiver', 'creator', 'employees:id,name,position']));
    }

    public function close(Request $request, Bill $bill): JsonResponse
    {
        abort_if($bill->isClosed(), 422, 'This bill is already closed.');
        abort_unless($this->isPaidBill($bill), 422, 'Only paid bills can be closed.');

        $bill->update([
            'status' => 'closed',
            'closed_at' => $bill->closed_at ?? now(),
            'updated_by' => $request->user()->id,
        ]);

        return $this->moneyJson($bill->fresh()->load(['customer', 'vehicle', 'items.part', 'payments.receiver', 'creator']));
    }

    public function oweIn(Request $request, Bill $bill): JsonResponse
    {
        abort_if($bill->isClosed(), 422, 'Closed bills cannot be marked owe in.');
        abort_if($bill->isOweIn(), 422, 'This bill is already on owe in.');
        abort_if($this->isPaidBill($bill), 422, 'This bill is fully paid. Close it instead.');
        abort_if((float) $bill->subtotal <= 0, 422, 'Add charges before marking this bill owe in.');

        $data = $request->validate([
            'due_date' => ['required', 'date', 'after_or_equal:today'],
        ]);

        $bill->update([
            'status' => 'owe_in',
            'owe_in_due_date' => $data['due_date'],
            'updated_by' => $request->user()->id,
        ]);

        return $this->moneyJson($bill->fresh()->load(['customer', 'vehicle', 'items.part', 'payments.receiver', 'creator']));
    }

    public function moveBranch(Request $request, Bill $bill): JsonResponse
    {
        abort_unless($request->user()->role === 'business_owner', 403);
        $data = $request->validate([
            'branch_id' => ['required', Rule::exists('branches', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        abort_if((int) $bill->branch_id === (int) $data['branch_id'], 422, 'This bill is already in that shop.');

        $fromId = (int) $bill->branch_id;
        $toId = (int) $data['branch_id'];

        DB::transaction(function () use ($bill, $fromId, $toId) {
            $bill->load('items.part');
            foreach ($bill->items as $item) {
                if (! $item->part_id || ! $item->part) {
                    continue;
                }
                $qty = (int) $item->quantity;
                $item->part->returnStock($qty, $fromId);
                $item->part->takeStock($qty, $toId);
            }
            $bill->update(['branch_id' => $toId, 'updated_by' => auth()->id()]);
            if ($bill->source_type && $bill->source_id) {
                $source = $bill->source;
                if ($source && isset($source->branch_id)) {
                    $source->update(['branch_id' => $toId]);
                }
            }
        });

        return $this->moneyJson($bill->fresh()->load(['customer', 'vehicle', 'items.part', 'payments', 'branch:id,name,code,address,phone']));
    }

    private function storeGeneric(Request $request, string $type): JsonResponse
    {
        $data = $request->validate([
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'regex:/^[0-9+() -]{7,20}$/'],
            'customer_address' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'internal_notes' => ['nullable', 'string'],
            'additional_note_color' => ['nullable', Rule::in(['blue', 'red'])],
            'admission_date' => ['nullable', 'date'],
            'job_kind' => ['nullable', Rule::in([Bill::JOB_KIND_REPAIR, Bill::JOB_KIND_PARTS_SALE])],
            ...$this->employeeIdsRules($request),
        ]);

        if (($data['job_kind'] ?? null) === Bill::JOB_KIND_REPAIR) {
            abort_unless(
                $request->user()->canAccessFeature('repair_bills'),
                422,
                'Repair bills are not enabled for this shop.'
            );
        }

        $bill = DB::transaction(function () use ($data, $request, $type) {
            $customer = Customer::resolveFromIntake(
                $data['customer_name'] ?? null,
                $data['customer_phone'] ?? null,
                $data['customer_address'] ?? null,
            );

            return $this->openBill($request, $customer->id, $data, $type);
        });

        return response()->json($bill, 201);
    }

    private function openBill(Request $request, int $customerId, array $data, string $type, ?int $vehicleId = null, ?string $sourceType = null, ?int $sourceId = null): Bill
    {
        $jobKind = $data['job_kind']
            ?? (BusinessTypes::usesVehicleJobs($type)
                ? Bill::JOB_KIND_REPAIR
                : (BusinessTypes::usesStoreCounter($type) ? Bill::JOB_KIND_PARTS_SALE : Bill::JOB_KIND_REPAIR));
        $prefix = BusinessTypes::usesStoreCounter($type) && $jobKind === Bill::JOB_KIND_REPAIR
            ? 'REP'
            : BusinessTypes::billPrefix($type);

        $bill = Bill::create([
            'bill_number' => $prefix.'-'.now()->format('Ymd').'-'.strtoupper(str()->random(6)),
            'vehicle_id' => $vehicleId,
            'customer_id' => $customerId,
            'admission_date' => $data['admission_date'] ?? today(),
            'odometer' => $data['odometer'] ?? null,
            'mileage' => $data['mileage'] ?? null,
            'next_service_mileage' => $data['next_service_mileage'] ?? null,
            'notes' => $data['notes'] ?? null,
            'internal_notes' => $data['internal_notes'] ?? null,
            'additional_note_color' => $data['additional_note_color'] ?? null,
            'job_kind' => $jobKind,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'created_by' => $request->user()->id,
        ]);
        $this->syncBillEmployees($bill, $data['employee_ids'] ?? []);

        return $bill->load(['customer', 'vehicle', 'items', 'payments', 'employees:id,name,position']);
    }

    /**
     * @return array<string, mixed>
     */
    private function employeeIdsRules(Request $request): array
    {
        return [
            'employee_ids' => ['nullable', 'array'],
            'employee_ids.*' => ['integer', Rule::exists('employees', 'id')->where('tenant_id', $request->user()->tenant_id)],
        ];
    }

    /**
     * @param  list<int|string>|null  $employeeIds
     */
    private function syncBillEmployees(Bill $bill, ?array $employeeIds): void
    {
        $ids = collect($employeeIds ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        $validIds = $ids->isEmpty()
            ? []
            : Employee::query()->whereIn('id', $ids)->pluck('id')->all();

        $bill->employees()->sync(
            collect($validIds)->mapWithKeys(fn (int $id) => [$id => ['tenant_id' => $bill->tenant_id]])->all()
        );
    }

    private function jobType(Request $request): string
    {
        return BusinessTypes::normalizeLegacy((string) ($request->user()->tenant?->business_type ?? BusinessTypes::GARAGE));
    }

    private function isPaidBill(Bill $bill): bool
    {
        return (float) $bill->amount_paid > 0 && (float) $bill->balance_due <= 0;
    }
}
