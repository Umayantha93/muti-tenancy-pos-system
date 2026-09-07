<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\BillItem;
use App\Support\WarrantyPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WarrantyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->canAccessFeature('warranties'), 403, 'Warranties are not enabled for this shop.');

        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'include_expired' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $search = trim((string) ($data['search'] ?? ''));
        $includeExpired = ! empty($data['include_expired']);

        $jobs = Bill::query()
            ->with(['customer:id,name,phone', 'vehicle:id,number_plate,make,model'])
            ->whereNotNull('warranty_until')
            ->when(! $includeExpired, fn ($query) => $query->whereDate('warranty_until', '>=', today()))
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.$search.'%';
                $query->where(function ($nested) use ($like) {
                    $nested->where('bill_number', 'like', $like)
                        ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', $like)->orWhere('phone', 'like', $like))
                        ->orWhereHas('vehicle', fn ($vehicle) => $vehicle->where('number_plate', 'like', $like)->orWhere('chassis_number', 'like', $like));
                });
            })
            ->latest('warranty_until')
            ->limit(50)
            ->get()
            ->map(fn (Bill $bill) => [
                'id' => 'job-'.$bill->id,
                'source' => 'bill',
                'description' => $bill->vehicle?->number_plate
                    ? 'Job warranty · '.$bill->vehicle->number_plate
                    : 'Job warranty',
                'warranty_months' => $bill->warranty_months,
                'warranty_starts_on' => $bill->warranty_starts_on?->toDateString(),
                'warranty_until' => $bill->warranty_until?->toDateString(),
                'bill' => [
                    'id' => $bill->id,
                    'bill_number' => $bill->bill_number,
                    'admission_date' => $bill->admission_date?->toDateString(),
                    'customer' => $bill->customer,
                ],
                'vehicle' => $bill->vehicle,
                'part' => null,
            ]);

        $items = BillItem::query()
            ->with([
                'bill:id,bill_number,admission_date,customer_id,status,branch_id',
                'bill.customer:id,name,phone',
                'bill.vehicle:id,number_plate',
                'part:id,name,sku,barcode,brand',
            ])
            ->whereHas('bill')
            ->whereNotNull('warranty_until')
            ->when(! $includeExpired, fn ($query) => $query->whereDate('warranty_until', '>=', today()))
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.$search.'%';
                $query->where(function ($nested) use ($like) {
                    $nested->where('description', 'like', $like)
                        ->orWhereHas('bill', fn ($bill) => $bill->where('bill_number', 'like', $like))
                        ->orWhereHas('bill.customer', fn ($customer) => $customer->where('name', 'like', $like)->orWhere('phone', 'like', $like))
                        ->orWhereHas('bill.vehicle', fn ($vehicle) => $vehicle->where('number_plate', 'like', $like))
                        ->orWhereHas('part', fn ($part) => $part->where('name', 'like', $like)->orWhere('sku', 'like', $like)->orWhere('barcode', 'like', $like));
                });
            })
            ->latest('warranty_until')
            ->paginate($data['per_page'] ?? 30);

        $itemRows = collect($items->items())->map(function (BillItem $item) {
            $row = $item->toArray();
            $row['id'] = 'item-'.$item->id;
            $row['source'] = 'item';

            return $row;
        });

        return $this->moneyJson([
            'data' => $jobs->concat($itemRows)->values(),
            'current_page' => $items->currentPage(),
            'last_page' => $items->lastPage(),
            'per_page' => $items->perPage(),
            'total' => $items->total() + $jobs->count(),
        ]);
    }

    public function updateBill(Request $request, Bill $bill): JsonResponse
    {
        abort_unless($request->user()->canAccessFeature('warranties'), 403, 'Warranties are not enabled for this shop.');
        abort_if($bill->status === 'closed' || $bill->isOweIn(), 422, $bill->isOweIn()
            ? 'Owe-in bills cannot be edited. Record a payment instead.'
            : 'Closed bills cannot be edited.');

        $data = $request->validate([
            'warranty_months' => ['nullable', 'integer', 'min:0', 'max:120'],
            'warranty_starts_on' => ['nullable', 'date'],
            'warranty_until' => ['nullable', 'date', 'after_or_equal:warranty_starts_on'],
        ]);

        $bill->update(WarrantyPeriod::resolve(
            $data['warranty_starts_on'] ?? null,
            $data['warranty_months'] ?? null,
            $data['warranty_until'] ?? null,
            $bill->admission_date,
        ));

        return $this->moneyJson($bill->fresh()->load(['customer', 'vehicle', 'items.part', 'payments.receiver', 'creator', 'employees:id,name,position', 'branch:id,name,code,address,phone']));
    }

    public function update(Request $request, Bill $bill, BillItem $item): JsonResponse
    {
        abort_unless($request->user()->canAccessFeature('warranties'), 403, 'Warranties are not enabled for this shop.');
        abort_unless($item->bill_id === $bill->id, 404);
        abort_if($bill->status === 'closed' || $bill->isOweIn(), 422, $bill->isOweIn()
            ? 'Owe-in bills cannot be edited. Record a payment instead.'
            : 'Closed bills cannot be edited.');

        $data = $request->validate([
            'warranty_months' => ['nullable', 'integer', 'min:0', 'max:120'],
            'warranty_starts_on' => ['nullable', 'date'],
            'warranty_until' => ['nullable', 'date', 'after_or_equal:warranty_starts_on'],
        ]);

        $item->update(WarrantyPeriod::resolve(
            $data['warranty_starts_on'] ?? null,
            $data['warranty_months'] ?? null,
            $data['warranty_until'] ?? null,
            $bill->admission_date,
        ));

        return $this->moneyJson([
            'item' => $item->fresh(),
            'bill' => $bill->fresh()->load(['customer', 'vehicle', 'items.part', 'payments']),
        ]);
    }
}
