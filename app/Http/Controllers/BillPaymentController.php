<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\BillPayment;
use App\Services\BillCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BillPaymentController extends Controller
{
    public function store(Request $request, Bill $bill, BillCalculator $calculator): JsonResponse
    {
        abort_if($bill->isClosed(), 422, 'Closed bills cannot accept payments.');
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', Rule::in(BillPayment::METHODS)],
            'reference' => ['nullable', 'string', 'max:100'],
            'paid_at' => ['nullable', 'date'],
            'cheque_number' => ['nullable', 'string', 'max:80'],
            'cheque_date' => ['nullable', 'date'],
        ]);

        if ($data['method'] === 'cheque') {
            if (empty($data['cheque_number'])) {
                throw ValidationException::withMessages(['cheque_number' => ['Cheque number is required.']]);
            }
            if (empty($data['cheque_date'])) {
                throw ValidationException::withMessages(['cheque_date' => ['Cheque date is required.']]);
            }
        }

        $payment = DB::transaction(function () use ($data, $bill, $request, $calculator) {
            $isCheque = $data['method'] === 'cheque';
            $payment = $bill->payments()->create([
                'amount' => $data['amount'],
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'cheque_number' => $isCheque ? $data['cheque_number'] : null,
                'cheque_date' => $isCheque ? $data['cheque_date'] : null,
                'cheque_status' => $isCheque ? BillPayment::CHEQUE_PENDING : null,
                'cleared_on' => null,
                'paid_at' => $isCheque
                    ? ($data['cheque_date'] ?? now())
                    : ($data['paid_at'] ?? now()),
                'received_by' => $request->user()->id,
            ]);
            $calculator->recalculate($bill);
            if ((float) $bill->fresh()->amount_paid > 0 && $bill->hide_amounts) {
                $bill->update(['hide_amounts' => false]);
            }

            return $payment;
        });

        return response()->json([
            'payment' => $payment->fresh(),
            'bill' => $bill->fresh()->load(['customer', 'vehicle', 'items.part', 'payments.receiver', 'refunds.items.billItem']),
        ], 201);
    }

    public function clear(Bill $bill, BillPayment $payment, BillCalculator $calculator): JsonResponse
    {
        abort_unless($payment->bill_id === $bill->id, 404);
        abort_if($bill->isClosed(), 422, 'Closed bills cannot be edited.');
        abort_unless($payment->isPendingCheque(), 422, 'Only pending cheques can be cleared.');

        DB::transaction(function () use ($payment, $bill, $calculator) {
            $clearedOn = now()->toDateString();
            $payment->update([
                'cheque_status' => BillPayment::CHEQUE_CLEARED,
                'cleared_on' => $clearedOn,
                'paid_at' => $clearedOn,
            ]);
            $calculator->recalculate($bill);
        });

        return response()->json([
            'payment' => $payment->fresh(),
            'bill' => $bill->fresh()->load(['customer', 'vehicle', 'items.part', 'payments.receiver', 'refunds.items.billItem']),
        ]);
    }

    public function bounce(Bill $bill, BillPayment $payment, BillCalculator $calculator): JsonResponse
    {
        abort_unless($payment->bill_id === $bill->id, 404);
        abort_if($bill->isClosed(), 422, 'Closed bills cannot be edited.');
        abort_unless($payment->isPendingCheque(), 422, 'Only pending cheques can be bounced.');

        DB::transaction(function () use ($payment, $bill, $calculator) {
            $payment->update([
                'cheque_status' => BillPayment::CHEQUE_BOUNCED,
                'cleared_on' => null,
            ]);
            $calculator->recalculate($bill);
        });

        return response()->json([
            'payment' => $payment->fresh(),
            'bill' => $bill->fresh()->load(['customer', 'vehicle', 'items.part', 'payments.receiver', 'refunds.items.billItem']),
        ]);
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
