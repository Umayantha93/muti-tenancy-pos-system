<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Support\BusinessTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

class BillShareController extends Controller
{
    public function show(string $token): JsonResponse
    {
        $token = Bill::normalizeShareToken($token);
        abort_unless(strlen($token) >= 8, 404);

        $bill = Bill::withoutGlobalScopes()
            ->where('share_token', $token)
            ->with([
                'customer' => fn ($query) => $query->withoutGlobalScopes()->select(['id', 'name', 'phone', 'address']),
                'vehicle' => fn ($query) => $query->withoutGlobalScopes()->select(['id', 'number_plate', 'make', 'model', 'chassis_number', 'imei', 'tyre_size', 'axle', 'fault_description', 'asset_kind']),
                'items' => fn ($query) => $query->withoutGlobalScopes()->select([
                    'id', 'bill_id', 'type', 'description', 'included_services', 'quantity', 'unit_price', 'line_total',
                    'panel_group_id', 'panel_name',
                ]),
                'payments' => fn ($query) => $query->withoutGlobalScopes()->select(['id', 'bill_id', 'amount', 'method', 'paid_at']),
                'tenant:id,business_name,business_type,logo,address,tin,contact_email,contact_phone,contact_phones,owner_email,owner_phone,owner_phones',
            ])
            ->firstOrFail();

        $bill->tenant?->setAppends(['logo_url']);
        $isPaint = (string) $bill->tenant?->business_type === BusinessTypes::PAINT;
        $isGarage = (string) $bill->tenant?->business_type === BusinessTypes::GARAGE;

        return response()->json([
            'bill_number' => $bill->bill_number,
            'admission_date' => $bill->admission_date?->format('Y-m-d'),
            'status' => $bill->status,
            'subtotal' => $bill->subtotal,
            'total_deductions' => $bill->total_deductions,
            'vat_rate' => $bill->vat_rate,
            'sscl_rate' => $bill->sscl_rate,
            'vat_amount' => $bill->vat_amount,
            'sscl_amount' => $bill->sscl_amount,
            'amount_paid' => $bill->amount_paid,
            'balance_due' => $bill->balance_due,
            'mileage' => $bill->mileage,
            'additional_note' => $isGarage ? $bill->internal_notes : null,
            'additional_note_color' => $isGarage ? $bill->additional_note_color : null,
            'customer' => $bill->customer,
            'vehicle' => $bill->vehicle,
            'items' => $isPaint
                ? $this->paintCustomerItems($bill->items)
                : $bill->items->map(fn ($item) => $this->shareItem($item, $item->type === 'labor')),
            'payments' => $bill->payments,
            'tenant' => $bill->tenant,
        ]);
    }

    /**
     * @param  Collection<int, \App\Models\BillItem>  $items
     * @return list<array<string, mixed>>
     */
    private function paintCustomerItems(Collection $items): array
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
                    'line_total' => number_format($total, 2, '.', ''),
                    'hide_hours' => true,
                ];

                continue;
            }

            $rows[] = $this->shareItem($item, $item->type === 'labor');
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function shareItem($item, bool $hideHours): array
    {
        return [
            'id' => $item->id,
            'bill_id' => $item->bill_id,
            'type' => $item->type,
            'description' => $item->description,
            'included_services' => $item->included_services,
            'quantity' => $hideHours ? null : $item->quantity,
            'unit_price' => $hideHours ? null : $item->unit_price,
            'line_total' => $item->line_total,
            'hide_hours' => $hideHours,
        ];
    }
}
