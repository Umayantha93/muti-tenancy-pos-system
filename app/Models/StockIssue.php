<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\StampsBranch;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'branch_id', 'part_id', 'quantity', 'unit_cost', 'returned_qty', 'service_names',
    'opened_at', 'closed_at', 'notes', 'created_by', 'closed_by',
])]
class StockIssue extends Model
{
    use BelongsToTenant, StampsBranch;

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'unit_cost' => 'decimal:2',
            'returned_qty' => 'float',
            'service_names' => 'array',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }

    public function usedQty(): float
    {
        return max(0.0, (float) $this->quantity - (float) $this->returned_qty);
    }

    public function totalCost(): float
    {
        return round($this->usedQty() * (float) $this->unit_cost, 2);
    }

    /**
     * @return list<string>
     */
    public function normalizedServiceNames(): array
    {
        return collect($this->service_names ?? [])
            ->map(fn ($name) => mb_strtolower(trim((string) $name)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
