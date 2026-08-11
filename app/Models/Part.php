<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'sku', 'barcode', 'brand', 'type', 'model', 'year', 'price', 'cost_price', 'stock_qty', 'images', 'description'])]
class Part extends Model
{
    use BelongsToTenant;

    protected $appends = ['image_urls'];

    protected function casts(): array
    {
        return [
            'images' => 'array',
            'price' => 'decimal:2',
            'cost_price' => 'decimal:2',
        ];
    }

    protected function imageUrls(): Attribute
    {
        return Attribute::get(fn () => collect($this->images ?? [])
            ->map(fn (string $path) => 'storage/'.$path)
            ->values()
            ->all());
    }
}
