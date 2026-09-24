<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\BillPhoto;
use App\Models\BillVideo;
use App\Models\Branch;
use App\Support\BusinessTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BillShareController extends Controller
{
    public function show(string $token): JsonResponse
    {
        $bill = $this->resolveBill($token, [
            'customer' => fn ($query) => $query->withoutGlobalScopes()->select(['id', 'name', 'phone', 'address']),
            'vehicle' => fn ($query) => $query->withoutGlobalScopes()->select(['id', 'number_plate', 'make', 'model', 'chassis_number', 'imei', 'tyre_size', 'axle', 'fault_description', 'asset_kind']),
            'items' => fn ($query) => $query->withoutGlobalScopes()->select([
                'id', 'bill_id', 'type', 'description', 'included_services', 'quantity', 'unit_price', 'line_total',
                'panel_group_id', 'panel_name', 'warranty_months', 'warranty_starts_on', 'warranty_until',
            ]),
            'payments' => fn ($query) => $query->withoutGlobalScopes()->select(['id', 'bill_id', 'amount', 'method', 'paid_at']),
            'photos' => fn ($query) => $query->withoutGlobalScopes()->latest(),
            'videos' => fn ($query) => $query->withoutGlobalScopes()->latest(),
            'tenant:id,business_name,business_type,logo,address,tin,contact_email,contact_phone,contact_phones,owner_email,owner_phone,owner_phones',
            'branch:id,name,address,phone,code',
        ]);

        $bill->tenant?->setAppends(['logo_url']);
        $isPaint = (string) $bill->tenant?->business_type === BusinessTypes::PAINT;
        $isGarage = (string) $bill->tenant?->business_type === BusinessTypes::GARAGE;
        $repairNote = $isGarage && $bill->isRepairNote();

        return response()->json([
            'bill_number' => $bill->bill_number,
            'admission_date' => $bill->admission_date?->format('Y-m-d'),
            'status' => $bill->status,
            'hide_amounts' => $repairNote,
            'subtotal' => $repairNote ? null : $bill->subtotal,
            'total_deductions' => $repairNote ? null : $bill->total_deductions,
            'vat_rate' => $repairNote ? null : $bill->vat_rate,
            'sscl_rate' => $repairNote ? null : $bill->sscl_rate,
            'vat_amount' => $repairNote ? null : $bill->vat_amount,
            'sscl_amount' => $repairNote ? null : $bill->sscl_amount,
            'amount_paid' => $repairNote ? null : $bill->amount_paid,
            'balance_due' => $repairNote ? null : $bill->balance_due,
            'customer_balance' => $repairNote ? null : $bill->customer_balance,
            'driver_name' => $bill->driver_name,
            'driver_phone' => $bill->driver_phone,
            'mileage' => $bill->mileage,
            'next_service_mileage' => $bill->next_service_mileage,
            'warranty_months' => $bill->warranty_months,
            'warranty_starts_on' => $bill->warranty_starts_on?->toDateString(),
            'warranty_until' => $bill->warranty_until?->toDateString(),
            'additional_note' => $isGarage ? $bill->internal_notes : null,
            'additional_note_color' => $isGarage ? $bill->additional_note_color : null,
            'customer' => $bill->customer,
            'vehicle' => $bill->vehicle,
            'items' => $isPaint
                ? $this->paintCustomerItems($bill->items, $repairNote)
                : $bill->items->map(fn ($item) => $this->shareItem($item, $item->type === 'labor', $repairNote)),
            'payments' => $repairNote ? [] : $bill->payments,
            'photos' => $bill->photos->map(fn (BillPhoto $photo) => [
                'id' => $photo->id,
                'original_name' => $photo->original_name,
                'size_bytes' => $photo->size_bytes,
            ])->values(),
            'videos' => $bill->videos->map(fn (BillVideo $video) => [
                'id' => $video->id,
                'original_name' => $video->original_name,
                'duration_seconds' => $video->duration_seconds,
                'size_bytes' => $video->size_bytes,
            ])->values(),
            'tenant' => $bill->tenant,
            'branch' => $bill->branch,
            'show_shop' => Branch::withoutGlobalScopes()
                ->where('tenant_id', $bill->tenant_id)
                ->where('status', 'active')
                ->count() > 1,
        ]);
    }

    public function photo(string $token, BillPhoto $photo): StreamedResponse
    {
        $bill = $this->resolveBill($token);
        abort_unless($photo->bill_id === $bill->id, 404);
        abort_unless(Storage::disk('local')->exists($photo->path), 404);

        return Storage::disk('local')->response($photo->path, $photo->original_name ?: 'job-photo.jpg', [
            'Content-Type' => 'image/jpeg',
        ]);
    }

    public function video(string $token, BillVideo $video): StreamedResponse
    {
        $bill = $this->resolveBill($token);
        abort_unless($video->bill_id === $bill->id, 404);
        abort_unless(Storage::disk('local')->exists($video->path), 404);

        return Storage::disk('local')->response($video->path, $video->original_name ?: 'job-video.mp4', [
            'Content-Type' => 'video/mp4',
        ]);
    }

    /**
     * @param  array<int|string, mixed>  $with
     */
    private function resolveBill(string $token, array $with = []): Bill
    {
        $token = Bill::normalizeShareToken($token);
        abort_unless(strlen($token) >= 8, 404);

        return Bill::withoutGlobalScopes()
            ->where('share_token', $token)
            ->with($with)
            ->firstOrFail();
    }

    /**
     * @param  Collection<int, \App\Models\BillItem>  $items
     * @return list<array<string, mixed>>
     */
    private function paintCustomerItems(Collection $items, bool $hideAmounts = false): array
    {
        $seen = [];
        $rows = [];

        foreach ($items as $item) {
            $groupId = $item->panel_group_id;
            if ($groupId) {
                if (isset($seen[$groupId])) {
                    continue;
                }
                $seen[$groupId] = true;
                $members = $items->where('panel_group_id', $groupId);
                $total = round((float) $members->sum(fn ($line) => (float) $line->line_total), 2);
                $first = $members->first();
                $rows[] = [
                    'id' => $first->id,
                    'bill_id' => $first->bill_id,
                    'type' => 'labor',
                    'description' => $first->panel_name ?: $first->description,
                    'included_services' => null,
                    'quantity' => null,
                    'unit_price' => null,
                    'line_total' => $hideAmounts ? null : number_format($total, 2, '.', ''),
                    'hide_hours' => true,
                    'hide_amounts' => $hideAmounts,
                ];

                continue;
            }

            $rows[] = $this->shareItem($item, $item->type === 'labor', $hideAmounts);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function shareItem($item, bool $hideHours, bool $hideAmounts = false): array
    {
        $hideRate = $hideHours || $hideAmounts;

        return [
            'id' => $item->id,
            'bill_id' => $item->bill_id,
            'type' => $item->type,
            'description' => $item->description,
            'included_services' => $item->included_services,
            'quantity' => $hideHours ? null : $item->quantity,
            'unit_price' => $hideRate ? null : $item->unit_price,
            'line_total' => $hideAmounts ? null : $item->line_total,
            'hide_hours' => $hideHours,
            'hide_amounts' => $hideAmounts,
            'warranty_months' => $item->warranty_months,
            'warranty_starts_on' => $item->warranty_starts_on
                ? \Illuminate\Support\Carbon::parse($item->warranty_starts_on)->toDateString()
                : null,
            'warranty_until' => $item->warranty_until
                ? \Illuminate\Support\Carbon::parse($item->warranty_until)->toDateString()
                : null,
        ];
    }
}
