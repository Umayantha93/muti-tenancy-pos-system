<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'category',
    'description',
    'amount',
    'expense_date',
    'payment_status',
    'due_date',
    'settled_at',
    'created_by',
    'updated_by',
])]
class Expense extends Model
{
    use BelongsToTenant;

    public const STATUS_PAID = 'paid';

    public const STATUS_CREDIT = 'credit';

    protected function casts(): array
    {
        return [
            'expense_date' => 'date',
            'due_date' => 'date',
            'settled_at' => 'datetime',
            'amount' => 'decimal:2',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
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
        });
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
        return $query->paid()->where(function ($nested) use ($month, $year) {
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
