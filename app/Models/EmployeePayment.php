<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\StampsBranch;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id',
    'bill_id',
    'kind',
    'amount',
    'paid_on',
    'created_by',
    'branch_id',
])]
class EmployeePayment extends Model
{
    use BelongsToTenant, StampsBranch;

    public const KIND_DAILY_WAGE = 'daily_wage';

    public const KIND_COMMISSION = 'commission';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'paid_on' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }
}
