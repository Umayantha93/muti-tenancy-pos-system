<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\BusinessTypes;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

/**
 * Garages: the service blueprint shared by every vehicle type. Each type can switch a service off
 * or set its own price (ServiceAddonPrice); staff may type a different price on the job card.
 * Paint shops: priced package buttons.
 */
#[Fillable([
    'name',
    'price',
    'sort_order',
    'is_full_service',
    'active',
])]
class ServiceAddon extends Model
{
    use BelongsToTenant;

    public static function seedDefaultsFor(int $tenantId, ?string $businessType = null): void
    {
        if ($businessType !== BusinessTypes::PAINT) {
            return;
        }

        if (static::withoutGlobalScopes()->where('tenant_id', $tenantId)->exists()) {
            return;
        }

        foreach (static::paintCatalog() as $row) {
            $addon = new static;
            $addon->forceFill([
                'tenant_id' => $tenantId,
                'name' => $row['name'],
                'price' => $row['price'],
                'sort_order' => $row['sort_order'],
                'is_full_service' => false,
                'active' => true,
            ])->save();
        }
    }

    /**
     * @return list<array{name: string, price: float, sort_order: int}>
     */
    public static function paintCatalog(): array
    {
        return [
            ['name' => 'Bumper respray', 'price' => 25000, 'sort_order' => 10],
            ['name' => 'Scratch & blend', 'price' => 12000, 'sort_order' => 20],
            ['name' => 'Full body — solid', 'price' => 95000, 'sort_order' => 30],
            ['name' => 'Full body — metallic', 'price' => 120000, 'sort_order' => 40],
            ['name' => 'Alloy refurb', 'price' => 8500, 'sort_order' => 50],
            ['name' => 'Interior spray', 'price' => 18000, 'sort_order' => 60],
        ];
    }

    /**
     * Fill name and type from a catalog addon when posting a bill line.
     * Garages: the typed price wins, otherwise the vehicle type's price; paint packages use their stored price.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function applyToItemPayload(array $data, int $tenantId, ?int $vehicleClassId = null): array
    {
        if (empty($data['service_addon_id'])) {
            return $data;
        }

        $addon = static::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->with('inclusions')
            ->find($data['service_addon_id']);

        if (! $addon || ! $addon->active) {
            throw ValidationException::withMessages([
                'service_addon_id' => ['This service addon is not available.'],
            ]);
        }

        $businessType = BusinessTypes::normalizeLegacy((string) Tenant::query()->whereKey($tenantId)->value('business_type'));
        if ($businessType === BusinessTypes::GARAGE) {
            $setting = $vehicleClassId
                ? ServiceAddonPrice::withoutGlobalScopes()
                    ->where('service_addon_id', $addon->id)
                    ->where('service_vehicle_class_id', $vehicleClassId)
                    ->first()
                : null;
            if ($setting && ! $setting->offered) {
                throw ValidationException::withMessages([
                    'service_addon_id' => ['This service is not offered for this vehicle type.'],
                ]);
            }
            $typed = $data['unit_price'] ?? null;
            $price = ($typed === null || $typed === '') ? ($setting?->price ?? 0) : $typed;
            if (! is_numeric($price) || (float) $price < 0) {
                throw ValidationException::withMessages([
                    'unit_price' => ['Enter a valid price for this service.'],
                ]);
            }
            $data['unit_price'] = round((float) $price, 2);
        } else {
            $data['unit_price'] = (float) $addon->price;
        }

        $data['type'] = 'service_addon';
        $data['description'] = $addon->name;
        $data['included_services'] = $addon->is_full_service
            ? $addon->inclusions->pluck('name')->filter()->values()->all()
            : null;
        $data['service_addon_id'] = $addon->id;
        $data['purchase_unit_cost'] = null;

        return $data;
    }

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'is_full_service' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function vehiclePrices(): HasMany
    {
        return $this->hasMany(ServiceAddonPrice::class);
    }

    public function inclusions(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'service_addon_inclusions',
            'full_service_addon_id',
            'included_addon_id',
        )->withTimestamps()->orderBy('name');
    }
}
