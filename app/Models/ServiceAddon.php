<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\BusinessTypes;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

#[Fillable([
    'service_vehicle_class_id',
    'name',
    'price',
    'sort_order',
    'is_full_service',
    'active',
])]
class ServiceAddon extends Model
{
    use BelongsToTenant;

    /**
     * @return list<array{name: string, price: float, sort_order: int, is_full_service?: bool}>
     */
    public static function defaultCatalog(): array
    {
        return [
            ['name' => 'Oil and filter change', 'price' => 4500, 'sort_order' => 10],
            ['name' => 'Body wash', 'price' => 800, 'sort_order' => 20],
            ['name' => 'Nipple grease', 'price' => 500, 'sort_order' => 30],
            ['name' => 'Engine wash', 'price' => 1200, 'sort_order' => 40],
            ['name' => 'Under wash', 'price' => 700, 'sort_order' => 50],
            ['name' => 'Brake service', 'price' => 2500, 'sort_order' => 60],
            ['name' => 'Vacuum', 'price' => 400, 'sort_order' => 70],
            ['name' => 'Interior cleaning', 'price' => 1500, 'sort_order' => 80],
            ['name' => 'Body polish', 'price' => 3500, 'sort_order' => 90],
            ['name' => 'Full service', 'price' => 8500, 'sort_order' => 100, 'is_full_service' => true],
        ];
    }

    public static function seedDefaultsFor(int $tenantId, ?string $businessType = null): void
    {
        if (static::withoutGlobalScopes()->where('tenant_id', $tenantId)->exists()) {
            return;
        }

        if ($businessType === BusinessTypes::PAINT) {
            foreach (static::paintCatalog() as $row) {
                $addon = new static;
                $addon->forceFill([
                    'tenant_id' => $tenantId,
                    'service_vehicle_class_id' => null,
                    'name' => $row['name'],
                    'price' => $row['price'],
                    'sort_order' => $row['sort_order'],
                    'is_full_service' => $row['is_full_service'] ?? false,
                    'active' => true,
                ])->save();
            }

            return;
        }

        ServiceVehicleClass::ensureDefaultsFor($tenantId, $businessType ?? BusinessTypes::GARAGE);
        $car = ServiceVehicleClass::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('name', 'Car')
            ->first();

        $created = [];
        foreach (static::defaultCatalog() as $row) {
            $addon = new static;
            $addon->forceFill([
                'tenant_id' => $tenantId,
                'service_vehicle_class_id' => $car?->id,
                'name' => $row['name'],
                'price' => $row['price'],
                'sort_order' => $row['sort_order'],
                'is_full_service' => $row['is_full_service'] ?? false,
                'active' => true,
            ])->save();
            $created[$row['name']] = $addon;
        }

        $full = $created['Full service'] ?? null;
        if (! $full) {
            return;
        }

        $includeNames = ['Body wash', 'Nipple grease', 'Engine wash', 'Under wash', 'Vacuum', 'Interior cleaning'];
        $ids = collect($includeNames)
            ->map(fn (string $name) => $created[$name]->id ?? null)
            ->filter()
            ->all();
        $full->inclusions()->sync($ids);
    }

    /**
     * Pick the vehicle class with the most active addons (tie → lowest sort_order, then id).
     */
    public static function richestSourceClassId(?int $exceptClassId = null): ?int
    {
        $query = static::query()
            ->where('active', true)
            ->whereNotNull('service_vehicle_class_id')
            ->selectRaw('service_vehicle_class_id, count(*) as addon_count')
            ->groupBy('service_vehicle_class_id')
            ->orderByDesc('addon_count');

        if ($exceptClassId !== null) {
            $query->where('service_vehicle_class_id', '!=', $exceptClassId);
        }

        $top = $query->get();
        if ($top->isEmpty()) {
            return null;
        }

        $maxCount = (int) $top->first()->addon_count;
        $tiedIds = $top->filter(fn ($row) => (int) $row->addon_count === $maxCount)
            ->pluck('service_vehicle_class_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $winner = ServiceVehicleClass::query()
            ->whereIn('id', $tiedIds)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->value('id');

        return $winner !== null ? (int) $winner : null;
    }

    /**
     * Clone active addons from one vehicle class onto another with price 0.
     * Preserves full-service inclusion links via the new id map.
     *
     * @return int Number of addons created
     */
    public static function copyCatalogToClass(int $fromClassId, int $toClassId): int
    {
        if ($fromClassId === $toClassId) {
            return 0;
        }

        if (static::query()->where('service_vehicle_class_id', $toClassId)->exists()) {
            return 0;
        }

        $source = static::query()
            ->where('service_vehicle_class_id', $fromClassId)
            ->where('active', true)
            ->with('inclusions')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($source->isEmpty()) {
            return 0;
        }

        return (int) DB::transaction(function () use ($source, $toClassId) {
            $idMap = [];

            foreach ($source->where('is_full_service', false) as $addon) {
                $clone = static::create([
                    'service_vehicle_class_id' => $toClassId,
                    'name' => $addon->name,
                    'price' => 0,
                    'sort_order' => $addon->sort_order,
                    'is_full_service' => false,
                    'active' => true,
                ]);
                $idMap[$addon->id] = $clone->id;
            }

            foreach ($source->where('is_full_service', true) as $addon) {
                $clone = static::create([
                    'service_vehicle_class_id' => $toClassId,
                    'name' => $addon->name,
                    'price' => 0,
                    'sort_order' => $addon->sort_order,
                    'is_full_service' => true,
                    'active' => true,
                ]);
                $idMap[$addon->id] = $clone->id;

                $inclusionIds = $addon->inclusions
                    ->map(fn (self $included) => $idMap[$included->id] ?? null)
                    ->filter()
                    ->values()
                    ->all();
                $clone->inclusions()->sync($inclusionIds);
            }

            return count($idMap);
        });
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
     * Fill name, price, and type from a catalog addon when posting a bill line.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function applyToItemPayload(array $data, int $tenantId): array
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

        $data['type'] = 'service_addon';
        $data['description'] = $addon->name;
        $data['included_services'] = $addon->is_full_service
            ? $addon->inclusions->pluck('name')->filter()->values()->all()
            : null;
        $data['unit_price'] = (float) $addon->price;
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

    public function vehicleClass(): BelongsTo
    {
        return $this->belongsTo(ServiceVehicleClass::class, 'service_vehicle_class_id');
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
