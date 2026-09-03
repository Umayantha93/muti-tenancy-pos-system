<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\BusinessTypes;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Validation\ValidationException;

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

        $catalog = $businessType === BusinessTypes::PAINT
            ? static::paintCatalog()
            : static::defaultCatalog();

        $created = [];
        foreach ($catalog as $row) {
            $addon = new static;
            $addon->forceFill([
                'tenant_id' => $tenantId,
                'name' => $row['name'],
                'price' => $row['price'],
                'sort_order' => $row['sort_order'],
                'is_full_service' => $row['is_full_service'] ?? false,
                'active' => true,
            ])->save();
            $created[$row['name']] = $addon;
        }

        if ($businessType === BusinessTypes::PAINT) {
            return;
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
