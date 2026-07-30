<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Services\BillCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BillPaymentController extends Controller
{
    public function store(Request $request, Bill $bill, BillCalculator $calculator): JsonResponse
    {
        abort_if($bill->status === 'closed', 422, 'Closed bills cannot accept payments.');
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
}
