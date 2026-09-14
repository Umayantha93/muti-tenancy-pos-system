<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'bill_id',
    'amount',
    'method',
    'reference',
    'cheque_number',
    'cheque_date',
    'cheque_status',
    'cleared_on',
    'paid_at',
    'received_by',
])]
class BillPayment extends Model
{
    use BelongsToTenant;

    public const METHODS = ['cash', 'card', 'bank_transfer', 'cheque', 'other'];

    public const CHEQUE_PENDING = 'pending';

    public const CHEQUE_CLEARED = 'cleared';

    public const CHEQUE_BOUNCED = 'bounced';

    protected function casts(): array
    {
        return [
            'paid_at' => 'datetime',
            'cheque_date' => 'date:Y-m-d',
            'cleared_on' => 'date:Y-m-d',
            'amount' => 'decimal:2',
        ];
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function isCheque(): bool
    {
        return $this->method === 'cheque';
    }

    public function isPendingCheque(): bool
    {
        return $this->isCheque() && $this->cheque_status === self::CHEQUE_PENDING;
    }

    public function isClearedCheque(): bool
    {
        return $this->isCheque() && $this->cheque_status === self::CHEQUE_CLEARED;
    }

    public function countsTowardPaid(): bool
    {
        if (! $this->isCheque()) {
            return true;
        }

        return $this->cheque_status === self::CHEQUE_CLEARED;
    }

    public function scopeCountingTowardPaid(Builder $query): Builder
    {
        return $query->where(function (Builder $nested) {
            $nested->where('method', '!=', 'cheque')
                ->orWhere('cheque_status', self::CHEQUE_CLEARED);
        });
    }

    public function scopePendingCheques(Builder $query): Builder
    {
        return $query->where('method', 'cheque')->where('cheque_status', self::CHEQUE_PENDING);
    }
}
