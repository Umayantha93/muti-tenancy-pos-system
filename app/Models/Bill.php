<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Tenant;
use App\Services\BillCalculator;
use App\Support\BusinessTypes;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
    'internal_notes',
    'status',
    'job_kind',
    'owe_in_due_date',
    'closed_at',
    'subtotal',
    'vat_rate',
    'sscl_rate',
    'vat_amount',
    'sscl_amount',
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

    public const JOB_KIND_REPAIR = 'repair';

    public const JOB_KIND_SERVICE = 'service';

    public const JOB_KIND_PARTS_SALE = 'parts_sale';

    protected static function booted(): void
    {
        static::creating(function (Bill $bill): void {
            if (! $bill->share_token) {
                $bill->share_token = static::newShareToken();
            }
            if ($bill->vat_rate === null || $bill->sscl_rate === null) {
                $tenant = $bill->tenant_id ? Tenant::query()->find($bill->tenant_id) : auth()->user()?->tenant;
                $rates = BillCalculator::snapshotRates($tenant);
                $bill->vat_rate ??= $rates['vat_rate'];
                $bill->sscl_rate ??= $rates['sscl_rate'];
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
            'vat_rate' => 'decimal:2',
            'sscl_rate' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'sscl_amount' => 'decimal:2',
            'total_deductions' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'balance_due' => 'decimal:2',
            'customer_balance' => 'decimal:2',
        ];
    }

    public static function normalizeShareToken(?string $token): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', (string) $token) ?? '');
    }

    public static function newShareToken(): string
    {
        do {
            $token = str()->lower(str()->random(24));
        } while (static::withoutGlobalScopes()->where('share_token', $token)->exists());

        return $token;
    }

    public function ensureShareToken(): string
    {
        if ($this->share_token) {
            return $this->share_token;
        }

        $this->forceFill(['share_token' => static::newShareToken()])->save();

        return $this->share_token;
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'bill_employees')
            ->withTimestamps()
            ->orderBy('employees.name');
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
