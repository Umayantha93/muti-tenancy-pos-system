<?php

namespace App\Services;

use App\Models\Bill;

class BillCalculator
{
    public function recalculate(Bill $bill): Bill
    {
        $charges = (float) $bill->items()->whereIn('type', ['charge', 'part', 'labor'])->sum('line_total');
        $discounts = (float) $bill->items()->where('type', 'discount')->sum('line_total');
        $advances = (float) $bill->items()->where('type', 'advance')->sum('line_total');
        $payments = (float) $bill->payments()->sum('amount');
        $amountPaid = $advances + $payments;
        $balance = max(0, $charges - $discounts - $amountPaid);

        $status = match (true) {
            $bill->status === 'closed' => 'closed',
            $charges > 0 && $balance <= 0 => 'paid',
            $amountPaid > 0 => 'partially_paid',
            default => 'open',
        };

        $bill->update([
            'subtotal' => $charges,
            'total_deductions' => $discounts + $advances,
            'amount_paid' => $amountPaid,
            'balance_due' => $balance,
            'status' => $status,
        ]);

        return $bill->refresh();
    }
}
