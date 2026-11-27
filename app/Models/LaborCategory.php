<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\BusinessTypes;
use App\Support\LaborCatalogDefaults;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'sort_order', 'catalog'])]
class LaborCategory extends Model
{
    use BelongsToTenant;

    public static function seedDefaultsFor(int $tenantId, ?string $businessType = null): void
    {
        $catalogKey = match ($businessType) {
            BusinessTypes::PAINT => 'paint',
            BusinessTypes::AC_SERVICE => 'ac',
            default => 'garage',
        };
        if (static::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('catalog', $catalogKey)->exists()) {
            return;
        }

        $rate = LaborCatalogDefaults::hourlyRate();
        $catalog = match ($catalogKey) {
            'paint' => LaborCatalogDefaults::paintCatalog(),
            'ac' => LaborCatalogDefaults::acCatalog(),
            default => LaborCatalogDefaults::catalog(),
        };

        foreach ($catalog as $categoryIndex => $category) {
            $row = new static;
            $row->forceFill([
                'tenant_id' => $tenantId,
                'catalog' => $catalogKey,
                'name' => $category['name'],
                'sort_order' => ($categoryIndex + 1) * 10,
            ])->save();

            foreach ($category['items'] as $itemIndex => $item) {
                $labor = new LaborItem;
                $labor->forceFill([
                    'tenant_id' => $tenantId,
                    'labor_category_id' => $row->id,
                    'name' => $item['name'],
                    'hourly_rate' => $rate,
                    'standard_hours' => (float) $item['hours'],
                    'sort_order' => ($itemIndex + 1) * 10,
                    'active' => true,
                ])->save();
            }
        }
    }

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(LaborItem::class)->orderBy('sort_order')->orderBy('name');
    }
}
