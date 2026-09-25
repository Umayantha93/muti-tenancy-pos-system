<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\BusinessTypes;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A vehicle category typed in by the garage owner (Car, SUV, Tata bus…).
 */
#[Fillable([
    'name',
    'sort_order',
    'active',
])]
class ServiceVehicleClass extends Model
{
    use BelongsToTenant;

    /**
     * Only used by the 2026_09_23 migration that introduced vehicle types.
     */
    public static function ensureDefaultsFor(int $tenantId, ?string $businessType = null): void
    {
        if ($businessType !== null && $businessType !== BusinessTypes::GARAGE) {
            return;
        }

        $tenant = Tenant::query()->find($tenantId);
        if (! $tenant || $tenant->business_type !== BusinessTypes::GARAGE) {
            return;
        }

        if (static::withoutGlobalScopes()->where('tenant_id', $tenantId)->exists()) {
            return;
        }

        foreach ([['Car', 10], ['Van', 20], ['Bus', 30]] as [$name, $sort]) {
            $class = new static;
            $class->forceFill([
                'tenant_id' => $tenantId,
                'name' => $name,
                'sort_order' => $sort,
                'active' => true,
            ])->save();
        }
    }

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
