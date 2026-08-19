<?php

namespace App\Services;

use App\Models\Bill;
use App\Support\BusinessTypes;

class BillCalculator
{
    public function recalculate(Bill $bill): Bill
    {
        $charges = (float) $bill->items()->whereIn('type', BusinessTypes::chargeItemTypes())->sum('line_total');
        $discounts = (float) $bill->items()->whereIn('type', BusinessTypes::discountItemTypes())->sum('line_total');
        $advances = (float) $bill->items()->where('type', 'advance')->sum('line_total');
        $payments = (float) $bill->payments()->sum('amount');
        $amountPaid = $advances + $payments;
        $netBill = max(0, $charges - $discounts);
        $balanceDue = max(0, $netBill - $amountPaid);
        $customerBalance = max(0, $amountPaid - $netBill);

        $wasOweIn = $bill->status === 'owe_in';
        $fullyPaid = $netBill > 0 && $balanceDue <= 0;
        $status = match (true) {
            $bill->status === 'closed' => 'closed',
            $wasOweIn && $fullyPaid => 'closed',
            $wasOweIn => 'owe_in',
            $fullyPaid => 'paid',
            $amountPaid > 0 => 'partially_paid',
            default => 'open',
        };

        $bill->update([
            'subtotal' => $charges,
            'total_deductions' => $discounts + $advances,
            'amount_paid' => $amountPaid,
            'balance_due' => $balanceDue,
            'customer_balance' => $customerBalance,
            'status' => $status,
            'closed_at' => $status === 'closed' ? ($bill->closed_at ?? now()) : $bill->closed_at,
        ]);

        return $bill->refresh();
    }
}
