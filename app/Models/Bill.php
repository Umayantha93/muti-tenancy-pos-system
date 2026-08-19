<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\BusinessTypes;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'bill_number',
    'share_token',
    'vehicle_id',
    'customer_id',
    'admission_date',
    'odometer',
    'mileage',
    'notes',
    'status',
    'owe_in_due_date',
    'closed_at',
    'subtotal',
    'total_deductions',
    'amount_paid',
    'balance_due',
    'customer_balance',
    'created_by',
    'updated_by',
    'source_type',
    'source_id',
])]
class Bill extends Model
{
    use BelongsToTenant;

    protected static function booted(): void
    {
        static::creating(function (Bill $bill): void {
            if (! $bill->share_token) {
                do {
                    $token = str()->lower(str()->random(40));
                } while (static::withoutGlobalScopes()->where('share_token', $token)->exists());

                $bill->share_token = $token;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'admission_date' => 'date:Y-m-d',
            'owe_in_due_date' => 'date:Y-m-d',
            'closed_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'total_deductions' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'balance_due' => 'decimal:2',
            'customer_balance' => 'decimal:2',
        ];
    }

    public function ensureShareToken(): string
    {
        if ($this->share_token) {
            return $this->share_token;
        }

        do {
            $token = str()->lower(str()->random(40));
        } while (static::withoutGlobalScopes()->where('share_token', $token)->exists());

        $this->forceFill(['share_token' => $token])->save();

        return $token;
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function items(): HasMany
    {
        return $this->hasMany(BillItem::class)
            ->orderByRaw(BusinessTypes::billItemDisplayOrderSql())
            ->orderBy('bill_items.id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(BillPayment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    public function isOweIn(): bool
    {
        return $this->status === 'owe_in';
    }

    public function isLockedForEdits(): bool
    {
        return in_array($this->status, ['closed', 'owe_in'], true);
    }

    public function acceptsPayments(): bool
    {
        return $this->status !== 'closed';
    }

    public function isOweInUrgent(?int $withinDays = 3): bool
    {
        if (! $this->isOweIn() || ! $this->owe_in_due_date) {
            return false;
        }

        return $this->owe_in_due_date->lte(now()->startOfDay()->addDays($withinDays));
    }

    /**
     * Job-card queue: urgent owe-in first, then open, partial, remaining owe-in, paid, closed.
     */
    public function scopeQueued($query)
    {
        $soon = now()->startOfDay()->addDays(3)->toDateString();

        return $query
            ->orderByRaw("
                CASE
                    WHEN status = 'owe_in' AND owe_in_due_date IS NOT NULL AND owe_in_due_date <= ? THEN 0
                    WHEN status = 'open' THEN 1
                    WHEN status = 'partially_paid' THEN 2
                    WHEN status = 'owe_in' THEN 3
                    WHEN status = 'paid' THEN 4
                    WHEN status = 'closed' THEN 5
                    ELSE 6
                END
            ", [$soon])
            ->orderByRaw("CASE WHEN status = 'owe_in' THEN owe_in_due_date ELSE '9999-12-31' END")
            ->orderByDesc('admission_date')
            ->orderByDesc('id');
    }
}
