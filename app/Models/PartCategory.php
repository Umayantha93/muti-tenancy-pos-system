<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name'])]
class PartCategory extends Model
{
    use BelongsToTenant;

    public function subcategories(): HasMany
    {
        return $this->hasMany(PartSubcategory::class)->orderBy('name');
    }

    public static function remember(?string $category, ?string $subcategory): void
    {
        $category = trim((string) $category);
        if ($category === '') {
            return;
        }

        $row = static::query()->firstOrCreate(['name' => $category]);
        $subcategory = trim((string) $subcategory);
        if ($subcategory !== '') {
            $row->subcategories()->firstOrCreate(['name' => $subcategory]);
        }
    }
}
