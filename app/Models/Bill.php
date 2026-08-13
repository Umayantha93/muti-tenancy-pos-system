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
    'notes',
    'status',
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
}
