<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\Customer;
use App\Models\Vehicle;
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
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $bills = Bill::query()
            ->with(['customer', 'vehicle', 'items', 'payments'])
            ->when(! empty($data['status']), fn ($query) => $query->where('status', $data['status']))
            ->when(! empty($data['search']), function ($query) use ($data) {
                $search = '%'.$data['search'].'%';
                $query->where(fn ($nested) => $nested->where('bill_number', 'like', $search)
                    ->orWhereHas('vehicle', fn ($vehicle) => $vehicle->where('number_plate', 'like', $search))
                    ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', $search)));
            })
            ->when(! empty($data['date_from']), fn ($query) => $query->whereDate('admission_date', '>=', $data['date_from']))
            ->when(! empty($data['date_to']), fn ($query) => $query->whereDate('admission_date', '<=', $data['date_to']))
            ->queued()
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
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['required', 'regex:/^[0-9+() -]{7,20}$/'],
            'customer_address' => ['nullable', 'string', 'max:255'],
            'number_plate' => ['required', 'string', 'max:30'],
            'chassis_number' => ['nullable', 'string', 'max:100'],
            'make' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'year' => ['nullable', 'integer', 'between:1900,'.(now()->year + 1)],
            'odometer' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
            'admission_date' => ['nullable', 'date'],
            'job_kind' => ['nullable', Rule::in([Bill::JOB_KIND_REPAIR, Bill::JOB_KIND_SERVICE])],
            'tyre_size' => ['nullable', 'string', 'max:40'],
            'axle' => ['nullable', 'string', 'max:40'],
            'imei' => ['nullable', 'string', 'max:40'],
            'fault_description' => ['nullable', 'string', 'max:255'],
            'asset_kind' => ['nullable', Rule::in(['vehicle', 'device'])],
        ]);

        $bill = DB::transaction(function () use ($data, $request) {
            $customer = Customer::firstOrCreate(
                ['phone' => $data['customer_phone']],
                ['name' => $data['customer_name'], 'address' => $data['customer_address'] ?? null],
            );
            $customer->update(['name' => $data['customer_name'], 'address' => $data['customer_address'] ?? $customer->address]);

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
            'notes' => ['nullable', 'string'],
            'admission_date' => ['nullable', 'date'],
            'job_kind' => ['nullable', Rule::in([Bill::JOB_KIND_REPAIR, Bill::JOB_KIND_SERVICE])],
        ]);

        $vehicle = Vehicle::with('customer')->findOrFail($data['vehicle_id']);
        $bill = $this->openBill($request, $vehicle->customer_id, $data, $this->jobType($request), $vehicle->id);

        return response()->json($bill, 201);
    }

    public function show(Bill $bill): JsonResponse
    {
        $bill->ensureShareToken();

        return $this->moneyJson($bill->load(['customer', 'vehicle', 'items.part', 'payments.receiver', 'creator']));
    }

    public function update(Request $request, Bill $bill): JsonResponse
    {
        abort_if($bill->isLockedForEdits(), 422, $bill->isOweIn()
            ? 'Owe-in bills cannot be edited. Record a payment instead.'
            : 'Closed bills cannot be edited.');

        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['open', 'partially_paid', 'paid', 'closed', 'owe_in'])],
            'notes' => ['nullable', 'string'],
            'odometer' => ['nullable', 'integer', 'min:0'],
            'mileage' => ['nullable', 'integer', 'min:0'],
        ]);

        if (($data['status'] ?? null) === 'closed' && ! $this->isPaidBill($bill)) {
            abort(422, 'Only paid bills can be closed.');
        }
        if (($data['status'] ?? null) === 'owe_in') {
            abort(422, 'Use the Owe In action to mark a bill on credit.');
        }
        $bill->update([...$data, 'updated_by' => $request->user()->id]);

        return response()->json($bill->refresh()->load(['customer', 'vehicle', 'items.part', 'payments']));
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

    private function storeGeneric(Request $request, string $type): JsonResponse
    {
        $data = $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['required', 'regex:/^[0-9+() -]{7,20}$/'],
            'customer_address' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'admission_date' => ['nullable', 'date'],
        ]);

        $bill = DB::transaction(function () use ($data, $request, $type) {
            $customer = Customer::firstOrCreate(
                ['phone' => $data['customer_phone']],
                ['name' => $data['customer_name'], 'address' => $data['customer_address'] ?? null],
            );
            $customer->update(['name' => $data['customer_name'], 'address' => $data['customer_address'] ?? $customer->address]);

            return $this->openBill($request, $customer->id, $data, $type);
        });

        return response()->json($bill, 201);
    }

    private function openBill(Request $request, int $customerId, array $data, string $type, ?int $vehicleId = null, ?string $sourceType = null, ?int $sourceId = null): Bill
    {
        return Bill::create([
            'bill_number' => BusinessTypes::billPrefix($type).'-'.now()->format('Ymd').'-'.strtoupper(str()->random(6)),
            'vehicle_id' => $vehicleId,
            'customer_id' => $customerId,
            'admission_date' => $data['admission_date'] ?? today(),
            'odometer' => $data['odometer'] ?? null,
            'mileage' => $data['mileage'] ?? null,
            'notes' => $data['notes'] ?? null,
            'job_kind' => BusinessTypes::usesVehicleJobs($type)
                ? ($data['job_kind'] ?? Bill::JOB_KIND_REPAIR)
                : Bill::JOB_KIND_REPAIR,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'created_by' => $request->user()->id,
        ])->load(['customer', 'vehicle', 'items', 'payments']);
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
