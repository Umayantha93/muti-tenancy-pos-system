<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\StampsBranch;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'business_date',
    'expected_cash',
    'expected_card',
    'expected_bank',
    'expected_cheque',
    'counted_cash',
    'counted_card',
    'counted_bank',
    'counted_cheque',
    'notes',
    'closed_at',
    'branch_id',
])]
class CashUp extends Model
{
    use BelongsToTenant, StampsBranch;

    protected function casts(): array
    {
        return [
            'business_date' => 'date:Y-m-d',
            'closed_at' => 'datetime',
            'expected_cash' => 'decimal:2',
            'expected_card' => 'decimal:2',
            'expected_bank' => 'decimal:2',
            'expected_cheque' => 'decimal:2',
            'counted_cash' => 'decimal:2',
            'counted_card' => 'decimal:2',
            'counted_bank' => 'decimal:2',
            'counted_cheque' => 'decimal:2',
        ];
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
