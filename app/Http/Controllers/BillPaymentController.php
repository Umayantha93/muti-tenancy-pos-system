<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\BillPayment;
use App\Services\BillCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BillPaymentController extends Controller
{
    public function store(Request $request, Bill $bill, BillCalculator $calculator): JsonResponse
    {
        abort_if($bill->isClosed(), 422, 'Closed bills cannot accept payments.');
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', Rule::in(['cash', 'card', 'bank_transfer', 'other'])],
            'reference' => ['nullable', 'string', 'max:100'],
            'paid_at' => ['nullable', 'date'],
        ]);

        $payment = DB::transaction(function () use ($data, $bill, $request, $calculator) {
            $payment = $bill->payments()->create([
                ...$data,
                'paid_at' => $data['paid_at'] ?? now(),
                'received_by' => $request->user()->id,
            ]);
            $calculator->recalculate($bill);

            return $payment;
        });

        return response()->json(['payment' => $payment, 'bill' => $bill->fresh()], 201);
    }

    public function destroy(Bill $bill, BillPayment $payment, BillCalculator $calculator): JsonResponse
    {
        abort_unless($payment->bill_id === $bill->id, 404);
        abort_if($bill->isLockedForEdits(), 422, $bill->isOweIn()
            ? 'Payments on owe-in bills cannot be removed.'
            : 'Closed bills cannot be edited.');

        DB::transaction(function () use ($bill, $payment, $calculator) {
            $payment->delete();
            $calculator->recalculate($bill);
        });

        return response()->json(['bill' => $bill->fresh()]);
    }
}
