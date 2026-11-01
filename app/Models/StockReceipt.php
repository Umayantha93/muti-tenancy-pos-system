<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\StampsBranch;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['supplier_id', 'expense_id', 'receipt_number', 'invoice_number', 'received_at', 'payment_status', 'due_date', 'branch_id'])]
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

    /**
     * The day the GRN was entered. That is received_at, or created_at when received_at was never stored.
     * Credit due_date is a payment date and must not select the report.
     */
    public function scopeEnteredBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->where(function (Builder $dates) use ($from, $to) {
            $dates->where(function (Builder $received) use ($from, $to) {
                $received->whereNotNull('received_at')
                    ->whereDate('received_at', '>=', $from)
                    ->whereDate('received_at', '<=', $to);
            })->orWhere(function (Builder $created) use ($from, $to) {
                $created->whereNull('received_at')
                    ->whereDate('created_at', '>=', $from)
                    ->whereDate('created_at', '<=', $to);
            });
        });
    }
}
