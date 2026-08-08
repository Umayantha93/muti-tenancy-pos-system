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
            ->with(['customer', 'vehicle'])
            ->when(! empty($data['status']), fn ($query) => $query->where('status', $data['status']))
            ->when(! empty($data['search']), function ($query) use ($data) {
                $search = '%'.$data['search'].'%';
                $query->where(fn ($nested) => $nested->where('bill_number', 'like', $search)
                    ->orWhereHas('vehicle', fn ($vehicle) => $vehicle->where('number_plate', 'like', $search))
                    ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', $search)));
            })
            ->when(! empty($data['date_from']), fn ($query) => $query->whereDate('admission_date', '>=', $data['date_from']))
            ->when(! empty($data['date_to']), fn ($query) => $query->whereDate('admission_date', '<=', $data['date_to']))
            ->latest('admission_date')
            ->orderByDesc('id')
            ->paginate($data['per_page'] ?? 15);

        return $this->moneyJson($bills);
    }

    public function store(Request $request): JsonResponse
    {
        $type = $request->user()->tenant?->business_type ?? BusinessTypes::GARAGE;

        if ($type !== BusinessTypes::GARAGE) {
            return $this->storeGeneric($request, $type);
        }

        $data = $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['required', 'regex:/^[0-9+() -]{7,20}$/'],
            'customer_address' => ['nullable', 'string', 'max:255'],
            'number_plate' => ['required', 'string', 'max:30'],
            'chassis_number' => ['required', 'string', 'max:100'],
            'make' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'year' => ['nullable', 'integer', 'between:1900,'.(now()->year + 1)],
            'odometer' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
            'admission_date' => ['nullable', 'date'],
        ]);

        $bill = DB::transaction(function () use ($data, $request) {
            $customer = Customer::firstOrCreate(
                ['phone' => $data['customer_phone']],
                ['name' => $data['customer_name'], 'address' => $data['customer_address'] ?? null],
            );
            $customer->update(['name' => $data['customer_name'], 'address' => $data['customer_address'] ?? $customer->address]);

            $vehicle = Vehicle::updateOrCreate(
                ['chassis_number' => strtoupper($data['chassis_number'])],
                [
                    'customer_id' => $customer->id,
                    'number_plate' => strtoupper($data['number_plate']),
                    'make' => $data['make'] ?? null,
                    'model' => $data['model'] ?? null,
                    'year' => $data['year'] ?? null,
                ],
            );

            return $this->openBill($request, $customer->id, $data, BusinessTypes::GARAGE, $vehicle->id);
        });

        return response()->json($bill, 201);
    }

    public function storeFromVehicle(Request $request): JsonResponse
    {
        $data = $request->validate([
            'vehicle_id' => ['required', Rule::exists('vehicles', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'odometer' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string'],
            'admission_date' => ['nullable', 'date'],
        ]);

        $vehicle = Vehicle::with('customer')->findOrFail($data['vehicle_id']);
        $bill = $this->openBill($request, $vehicle->customer_id, $data, BusinessTypes::GARAGE, $vehicle->id);

        return response()->json($bill, 201);
    }

    public function show(Bill $bill): JsonResponse
    {
        return $this->moneyJson($bill->load(['customer', 'vehicle', 'items.part', 'payments.receiver', 'creator']));
    }

    public function update(Request $request, Bill $bill): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(['open', 'partially_paid', 'paid', 'closed'])],
            'notes' => ['nullable', 'string'],
            'odometer' => ['nullable', 'integer', 'min:0'],
        ]);
        $bill->update([...$data, 'updated_by' => $request->user()->id]);

        return response()->json($bill->refresh()->load(['customer', 'vehicle', 'items.part', 'payments']));
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
            'notes' => $data['notes'] ?? null,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'created_by' => $request->user()->id,
        ])->load(['customer', 'vehicle', 'items', 'payments']);
    }
}
