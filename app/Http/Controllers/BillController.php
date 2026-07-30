<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\Customer;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BillController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $bills = Bill::query()
            ->with(['customer', 'vehicle'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = '%'.$request->string('search').'%';
                $query->where(fn ($nested) => $nested->where('bill_number', 'like', $search)
                    ->orWhereHas('vehicle', fn ($vehicle) => $vehicle->where('number_plate', 'like', $search))
                    ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', $search)));
            })
            ->latest('admission_date')
            ->paginate($request->integer('per_page', 15));

        return response()->json($bills);
    }

    public function store(Request $request): JsonResponse
    {
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

            $bill = Bill::create([
                'bill_number' => 'JOB-'.now()->format('Ymd').'-'.strtoupper(str()->random(6)),
                'vehicle_id' => $vehicle->id,
                'customer_id' => $customer->id,
                'admission_date' => $data['admission_date'] ?? today(),
                'odometer' => $data['odometer'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            return $bill->load(['customer', 'vehicle', 'items', 'payments']);
        });

        return response()->json($bill, 201);
    }

    public function show(Bill $bill): JsonResponse
    {
        return response()->json($bill->load(['customer', 'vehicle', 'items.part', 'payments.receiver', 'creator']));
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
}
