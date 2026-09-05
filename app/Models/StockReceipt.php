<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\StampsBranch;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['supplier_id', 'expense_id', 'receipt_number', 'received_at', 'payment_status', 'due_date', 'branch_id'])]
class StockReceipt extends Model
{
    use BelongsToTenant, StampsBranch;

    protected function casts(): array
    {
        return [
            'received_at' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockReceiptItem::class);
    }
}
