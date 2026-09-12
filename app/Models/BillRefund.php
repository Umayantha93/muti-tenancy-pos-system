<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\StampsBranch;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'bill_id',
    'refunded_at',
    'reason',
    'method',
    'amount',
    'created_by',
    'branch_id',
])]
class BillRefund extends Model
{
    use BelongsToTenant, StampsBranch;

    public const METHODS = ['cash', 'card', 'bank_transfer', 'other'];

    protected function casts(): array
    {
        return [
            'refunded_at' => 'date:Y-m-d',
            'amount' => 'decimal:2',
        ];
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BillRefundItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
