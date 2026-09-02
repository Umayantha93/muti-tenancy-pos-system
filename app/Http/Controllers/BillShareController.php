<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use Illuminate\Http\JsonResponse;

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
                ]),
                'payments' => fn ($query) => $query->withoutGlobalScopes()->select(['id', 'bill_id', 'amount', 'method', 'paid_at']),
                'tenant:id,business_name,business_type,logo,address,tin,contact_email,contact_phone,contact_phones,owner_email,owner_phone,owner_phones',
            ])
            ->firstOrFail();

        $bill->tenant?->setAppends(['logo_url']);

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
            'customer' => $bill->customer,
            'vehicle' => $bill->vehicle,
            'items' => $bill->items,
            'payments' => $bill->payments,
            'tenant' => $bill->tenant,
        ]);
    }
}
