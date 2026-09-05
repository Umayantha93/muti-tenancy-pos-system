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

        $items = BillItem::query()
            ->with([
                'bill:id,bill_number,admission_date,customer_id,status,branch_id',
                'bill.customer:id,name,phone',
                'part:id,name,sku,barcode,brand',
            ])
            ->whereHas('bill')
            ->whereNotNull('warranty_until')
            ->when(empty($data['include_expired']), fn ($query) => $query->whereDate('warranty_until', '>=', today()))
            ->when(! empty($data['search']), function ($query) use ($data) {
                $search = '%'.$data['search'].'%';
                $query->where(function ($nested) use ($search) {
                    $nested->where('description', 'like', $search)
                        ->orWhereHas('bill', fn ($bill) => $bill->where('bill_number', 'like', $search))
                        ->orWhereHas('bill.customer', fn ($customer) => $customer->where('name', 'like', $search)->orWhere('phone', 'like', $search))
                        ->orWhereHas('part', fn ($part) => $part->where('name', 'like', $search)->orWhere('sku', 'like', $search)->orWhere('barcode', 'like', $search));
                });
            })
            ->latest('warranty_until')
            ->paginate($data['per_page'] ?? 30);

        return $this->moneyJson($items);
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
