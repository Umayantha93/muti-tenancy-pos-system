<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\StampsBranch;
use App\Models\Tenant;
use App\Services\BillCalculator;
use App\Support\BusinessTypes;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
    'next_service_mileage',
    'next_service_due_on',
    'service_reminder_sent_at',
    'notes',
    'internal_notes',
    'additional_note_color',
    'warranty_months',
    'warranty_starts_on',
    'warranty_until',
    'status',
    'job_kind',
    'floor_status',
    'hide_amounts',
    'owe_in_due_date',
    'closed_at',
    'subtotal',
    'vat_rate',
    'sscl_rate',
    'vat_amount',
    'sscl_amount',
    'total_deductions',
    'amount_paid',
    'amount_refunded',
    'balance_due',
    'customer_balance',
    'created_by',
    'updated_by',
    'source_type',
    'source_id',
    'branch_id',
])]
class Bill extends Model
{
    use BelongsToTenant, StampsBranch;

    public const JOB_KIND_REPAIR = 'repair';

    public const JOB_KIND_SERVICE = 'service';

    public const JOB_KIND_PARTS_SALE = 'parts_sale';

    protected $appends = [
        'has_pending_cheque',
        'pending_cheque_date',
        'refund_status',
    ];

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
            'next_service_due_on' => 'date:Y-m-d',
            'service_reminder_sent_at' => 'datetime',
            'owe_in_due_date' => 'date:Y-m-d',
            'closed_at' => 'datetime',
            'warranty_months' => 'integer',
            'warranty_starts_on' => 'date:Y-m-d',
            'warranty_until' => 'date:Y-m-d',
            'hide_amounts' => 'boolean',
            'subtotal' => 'decimal:2',
            'vat_rate' => 'decimal:2',
            'sscl_rate' => 'decimal:2',
            'vat_amount' => 'decimal:2',
            'sscl_amount' => 'decimal:2',
            'total_deductions' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'amount_refunded' => 'decimal:2',
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

    public function refunds(): HasMany
    {
        return $this->hasMany(BillRefund::class)->latest('refunded_at')->latest('id');
    }

    public function videos(): HasMany
    {
        return $this->hasMany(BillVideo::class)->latest();
    }

    public function acceptsRefunds(): bool
    {
        return $this->isClosed()
            && (float) $this->amount_paid > 0
            && round((float) $this->amount_paid - (float) $this->amount_refunded, 2) > 0;
    }

    public function resolvedRefundStatus(): string
    {
        $refunded = round((float) $this->amount_refunded, 2);
        if ($refunded <= 0) {
            return 'none';
        }

        $paid = round((float) $this->amount_paid, 2);

        return $refunded >= $paid ? 'refunded' : 'partially_refunded';
    }

    protected function refundStatus(): Attribute
    {
        return Attribute::get(fn () => $this->resolvedRefundStatus());
    }

    protected function hasPendingCheque(): Attribute
    {
        return Attribute::get(function () {
            if ($this->relationLoaded('payments')) {
                return $this->payments->contains(fn (BillPayment $payment) => $payment->isPendingCheque());
            }

            return $this->payments()->pendingCheques()->exists();
        });
    }

    protected function pendingChequeDate(): Attribute
    {
        return Attribute::get(function () {
            if ($this->relationLoaded('payments')) {
                return $this->payments
                    ->filter(fn (BillPayment $payment) => $payment->isPendingCheque())
                    ->map(fn (BillPayment $payment) => $payment->cheque_date?->toDateString())
                    ->filter()
                    ->sort()
                    ->first();
            }

            $date = $this->payments()->pendingCheques()->orderBy('cheque_date')->value('cheque_date');

            return $date ? (string) $date : null;
        });
    }

    public function hasPendingCheques(): bool
    {
        return (bool) $this->has_pending_cheque;
    }

    public function isRepairNote(): bool
    {
        return (bool) $this->hide_amounts && (float) $this->amount_paid <= 0;
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
