<?php

namespace App\Support;

use App\Models\Expense;
use App\Models\StockReceipt;
use Illuminate\Validation\ValidationException;

class InventoryExpenseAmounts
{
    public static function assertCanApply(?Expense $expense, float $newAmount, string $errorKey = 'stock_qty'): void
    {
        if (! $expense || $expense->payment_status === Expense::STATUS_PAID) {
            return;
        }

        $locked = round((float) $expense->amount_paid + $expense->pendingChequeTotal(), 2);
        if ($newAmount + 0.001 < $locked) {
            throw ValidationException::withMessages([
                $errorKey => ['Payments or cheques on this purchase are larger than the corrected amount. Clear those first, then correct the purchase.'],
            ]);
        }
    }

    public static function apply(?Expense $expense, float $newAmount): void
    {
        if (! $expense) {
            return;
        }

        $newAmount = round(max(0, $newAmount), 2);
        if ($newAmount <= 0) {
            $receipt = $expense->stock_receipt_id
                ? StockReceipt::query()->find($expense->stock_receipt_id)
                : null;
            $receipt?->update(['expense_id' => null]);
            self::delete($expense);

            return;
        }

        if ($expense->payment_status === Expense::STATUS_PAID) {
            $expense->update([
                'amount' => $newAmount,
                'amount_paid' => $newAmount,
                'settled_at' => $expense->settled_at ?? now(),
            ]);

            return;
        }

        $oldAmount = round((float) $expense->amount, 2);
        if ($newAmount >= $oldAmount) {
            $expense->update([
                'amount' => $newAmount,
                'settled_at' => null,
            ]);

            return;
        }

        self::reduce($expense, round($oldAmount - $newAmount, 2));
    }

    public static function delete(?Expense $expense): void
    {
        if (! $expense) {
            return;
        }

        $expense->update(['stock_receipt_id' => null]);
        $expense->delete();
    }

    public static function reduce(?Expense $expense, float $lineTotal): void
    {
        if (! $expense || $lineTotal <= 0) {
            return;
        }

        $newAmount = round(max(0, (float) $expense->amount - $lineTotal), 2);
        if ($newAmount <= 0) {
            $receipt = $expense->stock_receipt_id
                ? StockReceipt::query()->find($expense->stock_receipt_id)
                : null;
            $receipt?->update(['expense_id' => null]);
            self::delete($expense);

            return;
        }

        $newPaid = round(min((float) $expense->amount_paid, $newAmount), 2);
        $fullyPaid = $newPaid + 0.00001 >= $newAmount;
        $expense->update([
            'amount' => $newAmount,
            'amount_paid' => $newPaid,
            'payment_status' => $fullyPaid ? Expense::STATUS_PAID : Expense::STATUS_CREDIT,
            'settled_at' => $fullyPaid ? ($expense->settled_at ?? now()) : null,
        ]);

        $paid = 0.0;
        foreach ($expense->settlements()->orderBy('id')->get() as $settlement) {
            if ($paid >= $newPaid) {
                $settlement->delete();

                continue;
            }
            $next = round($paid + (float) $settlement->amount, 2);
            if ($next > $newPaid) {
                $settlement->update(['amount' => round($newPaid - $paid, 2)]);
                $paid = $newPaid;
            } else {
                $paid = $next;
            }
        }
    }
}
