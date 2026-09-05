<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\StampsBranch;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'category',
    'description',
    'amount',
    'amount_paid',
    'expense_date',
    'payment_status',
    'due_date',
    'settled_at',
    'created_by',
    'updated_by',
    'supplier_id',
    'stock_receipt_id',
    'branch_id',
])]
class Expense extends Model
{
    use BelongsToTenant, StampsBranch;

    public const STATUS_PAID = 'paid';

    public const STATUS_CREDIT = 'credit';

    protected function casts(): array
    {
        return [
            'expense_date' => 'date',
            'due_date' => 'date',
            'settled_at' => 'datetime',
            'amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function settlements(): HasMany
    {
        return $this->hasMany(ExpenseSettlement::class);
    }

    protected static function booted(): void
    {
        static::creating(function (Expense $expense): void {
            if (! $expense->payment_status) {
                $expense->payment_status = self::STATUS_PAID;
            }
            if ($expense->payment_status === self::STATUS_PAID && ! $expense->settled_at) {
                $expense->settled_at = $expense->expense_date ?? now();
            }
            if ($expense->amount_paid === null) {
                $expense->amount_paid = $expense->payment_status === self::STATUS_PAID
                    ? $expense->amount
                    : 0;
            }
        });
    }

    public function remainingAmount(): float
    {
        return round(max(0, (float) $this->amount - (float) $this->amount_paid), 2);
    }

    public function isCredit(): bool
    {
        return $this->payment_status === self::STATUS_CREDIT;
    }

    public function scopePaid($query)
    {
        return $query->where('payment_status', self::STATUS_PAID);
    }

    public function scopeCredit($query)
    {
        return $query->where('payment_status', self::STATUS_CREDIT);
    }

    public function scopePostedIn($query, int $month, int $year)
    {
        return $query->paid()
            ->whereDoesntHave('settlements')
            ->where(function ($nested) use ($month, $year) {
                $nested->where(function ($settled) use ($month, $year) {
                    $settled->whereNotNull('settled_at')
                        ->whereYear('settled_at', $year)
                        ->whereMonth('settled_at', $month);
                })->orWhere(function ($legacy) use ($month, $year) {
                    $legacy->whereNull('settled_at')
                        ->whereYear('expense_date', $year)
                        ->whereMonth('expense_date', $month);
                });
            });
    }
}
