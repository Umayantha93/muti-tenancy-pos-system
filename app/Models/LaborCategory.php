<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\LaborCatalogDefaults;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'sort_order'])]
class LaborCategory extends Model
{
    use BelongsToTenant;

    public static function seedDefaultsFor(int $tenantId): void
    {
        if (static::withoutGlobalScopes()->where('tenant_id', $tenantId)->exists()) {
            return;
        }

        $rate = LaborCatalogDefaults::hourlyRate();
        foreach (LaborCatalogDefaults::catalog() as $categoryIndex => $category) {
            $row = new static;
            $row->forceFill([
                'tenant_id' => $tenantId,
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
                    'standard_hours' => $item['hours'],
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
