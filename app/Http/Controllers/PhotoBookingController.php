<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\BillItem;
use App\Models\Customer;
use App\Models\PhotoBooking;
use App\Models\PhotoPackage;
use App\Support\BusinessTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PhotoBookingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $bookings = PhotoBooking::query()
            ->with(['customer', 'package', 'bill'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = '%'.$request->string('search').'%';
                $q->whereHas('customer', fn ($c) => $c->where('name', 'like', $search)->orWhere('phone', 'like', $search));
            })
            ->latest('scheduled_at')
            ->paginate($request->integer('per_page', 15));

        return response()->json($bookings);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'customer_phone' => ['required', 'regex:/^[0-9+() -]{7,20}$/'],
            'customer_address' => ['nullable', 'string', 'max:255'],
            'photo_package_id' => ['nullable', Rule::exists('photo_packages', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'scheduled_at' => ['nullable', 'date'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(['inquiry', 'booked', 'in_progress', 'delivered', 'cancelled'])],
            'create_bill' => ['sometimes', 'boolean'],
        ]);

        $booking = DB::transaction(function () use ($data, $request) {
            $customer = Customer::firstOrCreate(
                ['phone' => $data['customer_phone']],
                ['name' => $data['customer_name'], 'address' => $data['customer_address'] ?? null],
            );
            $customer->update(['name' => $data['customer_name'], 'address' => $data['customer_address'] ?? $customer->address]);

            $booking = PhotoBooking::create([
                'customer_id' => $customer->id,
                'photo_package_id' => $data['photo_package_id'] ?? null,
                'scheduled_at' => $data['scheduled_at'] ?? null,
                'location' => $data['location'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => $data['status'] ?? 'booked',
                'created_by' => $request->user()->id,
            ]);

            if ($request->boolean('create_bill', true)) {
                $bill = Bill::create([
                    'bill_number' => BusinessTypes::billPrefix(BusinessTypes::PHOTOGRAPHY).'-'.now()->format('Ymd').'-'.strtoupper(str()->random(6)),
                    'customer_id' => $customer->id,
                    'admission_date' => today(),
                    'notes' => $data['notes'] ?? null,
                    'source_type' => PhotoBooking::class,
                    'source_id' => $booking->id,
                    'created_by' => $request->user()->id,
                ]);
                $booking->update(['bill_id' => $bill->id]);

                if (! empty($data['photo_package_id'])) {
                    $package = PhotoPackage::find($data['photo_package_id']);
                    if ($package) {
                        BillItem::create([
                            'bill_id' => $bill->id,
                            'type' => 'service',
                            'description' => $package->name,
                            'quantity' => 1,
                            'unit_price' => $package->price,
                            'line_total' => $package->price,
                        ]);
                        $bill->update([
                            'subtotal' => $package->price,
                            'balance_due' => $package->price,
                        ]);
                    }
                }
            }

            return $booking->load(['customer', 'package', 'bill.items']);
        });

        return response()->json($booking, 201);
    }

    public function show(PhotoBooking $booking): JsonResponse
    {
        return response()->json($booking->load(['customer', 'package', 'bill.items', 'bill.payments', 'creator']));
    }

    public function update(Request $request, PhotoBooking $booking): JsonResponse
    {
        $data = $request->validate([
            'scheduled_at' => ['nullable', 'date'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'status' => ['sometimes', Rule::in(['inquiry', 'booked', 'in_progress', 'delivered', 'cancelled'])],
            'photo_package_id' => ['nullable', Rule::exists('photo_packages', 'id')->where('tenant_id', $request->user()->tenant_id)],
        ]);
        $booking->update($data);

        return response()->json($booking->refresh()->load(['customer', 'package', 'bill']));
    }
}
