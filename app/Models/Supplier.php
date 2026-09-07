<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'phone',
    'phone_secondary',
    'email',
    'address',
    'tin',
    'contact_person',
    'notes',
    'is_system',
    'active',
])]
class Supplier extends Model
{
    use BelongsToTenant;

    public const WALK_IN_NAME = 'Walk-in / unnamed';

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public static function ensureWalkInFor(int $tenantId): self
    {
        $existing = static::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('is_system', true)
            ->first();
        if ($existing) {
            return $existing;
        }

        $supplier = new static;
        $supplier->forceFill([
            'tenant_id' => $tenantId,
            'name' => self::WALK_IN_NAME,
            'notes' => 'Cash buys with no named house',
            'is_system' => true,
            'active' => true,
        ])->save();

        return $supplier;
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(StockReceipt::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }
}
