<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'business_name',
    'sms_sender_id',
    'business_type',
    'owner_name',
    'owner_phone',
    'owner_phones',
    'owner_email',
    'contact_email',
    'contact_phone',
    'contact_phones',
    'address',
    'status',
    'dual_financial_view_enabled',
    'plan',
    'payment_plan',
    'plan_amount',
    'logo',
])]
class Tenant extends Model
{
    use SoftDeletes;

    protected $appends = ['logo_url', 'payment_due_soon', 'suggested_sms_sender_id'];

    protected $hidden = ['dual_financial_view_enabled'];

    protected function casts(): array
    {
        return [
            'contact_phones' => 'array',
            'owner_phones' => 'array',
            'plan_amount' => 'decimal:2',
            'dual_financial_view_enabled' => 'boolean',
        ];
    }

    public static function suggestSmsSenderId(?string $businessName): ?string
    {
        $raw = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $businessName) ?? '');

        if ($raw === '') {
            return null;
        }

        return substr($raw, 0, 11);
    }

    public function resolvedSmsSenderId(): ?string
    {
        if (filled($this->sms_sender_id)) {
            return $this->sms_sender_id;
        }

        $fallback = config('services.notify_lk.sender_id');

        return filled($fallback) ? (string) $fallback : null;
    }

    public function secondaryUser(): ?User
    {
        return $this->users()->where('is_secondary_view', true)->first();
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function features(): BelongsToMany
    {
        return $this->belongsToMany(Feature::class, 'tenant_features')->withPivot('is_enabled')->withTimestamps();
    }

    public function feePayments(): HasMany
    {
        return $this->hasMany(TenantFeePayment::class);
    }

    protected function logoUrl(): Attribute
    {
        return Attribute::get(fn () => $this->logo ? 'storage/'.$this->logo : null);
    }

    protected function suggestedSmsSenderId(): Attribute
    {
        return Attribute::get(fn () => static::suggestSmsSenderId($this->business_name));
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
