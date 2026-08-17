<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use Illuminate\Http\JsonResponse;

class BillShareController extends Controller
{
    public function show(string $token): JsonResponse
    {
        $bill = Bill::query()
            ->where('share_token', $token)
            ->with([
                'customer:id,name,phone,address',
                'vehicle:id,number_plate,make,model,chassis_number',
                'items:id,bill_id,type,description,quantity,unit_price,line_total',
                'payments:id,bill_id,amount,method,paid_at',
                'tenant:id,business_name,business_type,logo,address,contact_email,contact_phone,contact_phones,owner_email,owner_phone,owner_phones',
            ])
            ->firstOrFail();

        return response()->json([
            'bill_number' => $bill->bill_number,
            'admission_date' => $bill->admission_date?->format('Y-m-d'),
            'status' => $bill->status,
            'subtotal' => $bill->subtotal,
            'total_deductions' => $bill->total_deductions,
            'amount_paid' => $bill->amount_paid,
            'balance_due' => $bill->balance_due,
            'mileage' => $bill->mileage,
            'notes' => $bill->notes,
            'customer' => $bill->customer,
            'vehicle' => $bill->vehicle,
            'items' => $bill->items,
            'payments' => $bill->payments,
            'tenant' => $bill->tenant,
        ]);
    }
}
