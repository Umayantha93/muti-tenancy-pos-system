<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\BusinessTypes;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'sort_order',
    'active',
])]
class ServiceVehicleClass extends Model
{
    use BelongsToTenant;

    /**
     * @return list<array{name: string, sort_order: int}>
     */
    public static function defaultCatalog(): array
    {
        return [
            ['name' => 'Car', 'sort_order' => 10],
            ['name' => 'Van', 'sort_order' => 20],
            ['name' => 'Bus', 'sort_order' => 30],
        ];
    }

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

        foreach (static::defaultCatalog() as $row) {
            $class = new static;
            $class->forceFill([
                'tenant_id' => $tenantId,
                'name' => $row['name'],
                'sort_order' => $row['sort_order'],
                'active' => true,
            ])->save();
        }
    }

    public function addons(): HasMany
    {
        return $this->hasMany(ServiceAddon::class)->orderBy('sort_order')->orderBy('id');
    }

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
