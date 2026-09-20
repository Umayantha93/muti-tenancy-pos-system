<?php

namespace App\Models;

use App\Support\BusinessTypes;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'business_name',
    'business_type',
    'owner_name',
    'owner_phone',
    'owner_phones',
    'owner_email',
    'contact_email',
    'contact_phone',
    'contact_phones',
    'address',
    'tin',
    'vat_registered',
    'sscl_registered',
    'vat_rate',
    'sscl_rate',
    'status',
    'demo_ends_at',
    'dual_financial_view_enabled',
    'plan',
    'payment_plan',
    'plan_amount',
    'setup_fee_amount',
    'logo',
])]
class Tenant extends Model
{
    use SoftDeletes;

    protected $appends = ['logo_url', 'payment_due_soon', 'demo_days_left', 'is_demo'];

    protected $hidden = ['dual_financial_view_enabled'];

    protected function casts(): array
    {
        return [
            'contact_phones' => 'array',
            'owner_phones' => 'array',
            'plan_amount' => 'decimal:2',
            'setup_fee_amount' => 'decimal:2',
            'dual_financial_view_enabled' => 'boolean',
            'vat_registered' => 'boolean',
            'sscl_registered' => 'boolean',
            'vat_rate' => 'decimal:2',
            'sscl_rate' => 'decimal:2',
            'demo_ends_at' => 'datetime',
        ];
    }

    public function expireDemoIfNeeded(): bool
    {
        if ($this->status !== 'active' || ! $this->demo_ends_at || $this->demo_ends_at->isFuture()) {
            return false;
        }

        $this->update(['status' => 'inactive']);
        $this->users()->each(fn (User $user) => $user->tokens()->delete());

        return true;
    }

    protected function isDemo(): Attribute
    {
        return Attribute::get(fn () => $this->demo_ends_at !== null);
    }

    protected function demoDaysLeft(): Attribute
    {
        return Attribute::get(function () {
            if (! $this->demo_ends_at || $this->status !== 'active') {
                return null;
            }

            return (int) now()->startOfDay()->diffInDays($this->demo_ends_at->copy()->endOfDay(), false);
        });
    }

    public function secondaryUser(): ?User
    {
        return $this->users()->where('is_secondary_view', true)->first();
    }

    protected static function booted(): void
    {
        static::created(function (Tenant $tenant): void {
            Branch::ensureDefault($tenant);
            if ($tenant->business_type === BusinessTypes::GARAGE) {
                Supplier::ensureWalkInFor((int) $tenant->id);
            }
        });
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function features(): BelongsToMany
    {
        return $this->belongsToMany(Feature::class, 'tenant_features')->withPivot('is_enabled')->withTimestamps();
    }

    public function feePayments(): HasMany
    {
        return $this->hasMany(TenantFeePayment::class);
    }

    public function setupFeePayments(): HasMany
    {
        return $this->hasMany(TenantSetupFeePayment::class);
    }

    public function withSetupFeeTotals(): static
    {
        $total = round((float) ($this->setup_fee_amount ?? 0), 2);
        $paid = round((float) ($this->setup_fee_payments_sum_amount ?? $this->setupFeePayments()->sum('amount')), 2);
        $balance = round(max(0, $total - $paid), 2);
        $this->setAttribute('setup_fee_paid', $paid);
        $this->setAttribute('setup_fee_balance', $balance);
        $this->setAttribute('setup_fee_settled', $total > 0 && $balance <= 0);

        return $this;
    }

    protected function logoUrl(): Attribute
    {
        return Attribute::get(fn () => $this->logo ? 'storage/'.$this->logo : null);
    }

    protected function paymentDueSoon(): Attribute
    {
        return Attribute::get(function () {
            if ($this->payment_plan !== 'monthly' || $this->plan_amount === null) {
                return false;
            }
            $today = now()->day;
            $lastDay = now()->daysInMonth;
            if ($today <= ($lastDay - 5)) {
                return false;
            }

            return ! $this->feePayments()
                ->where('year', now()->year)
                ->where('month', now()->month)
                ->exists();
        });
    }
}
