<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'bill_id',
    'part_id',
    'type',
    'description',
    'quantity',
    'unit_price',
    'purchase_unit_cost',
    'purchase_expense_id',
    'line_total',
])]
class BillItem extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'purchase_unit_cost' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    public function purchaseExpense(): BelongsTo
    {
        return $this->belongsTo(Expense::class, 'purchase_expense_id');
    }
}
