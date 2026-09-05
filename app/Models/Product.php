<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Services\BranchInventory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'sku', 'category', 'size', 'color', 'price', 'cost_price', 'stock_qty', 'images', 'description', 'active'])]
class Product extends Model
{
    use BelongsToTenant;

    protected $appends = ['image_urls'];

    protected static function booted(): void
    {
        static::created(function (Product $product): void {
            if (BranchInventory::$mutating) {
                return;
            }
            BranchInventory::seedProduct($product, (int) $product->stock_qty);
        });

        static::saved(function (Product $product): void {
            if (BranchInventory::$mutating || $product->wasRecentlyCreated || ! $product->wasChanged('stock_qty')) {
                return;
            }
            BranchInventory::setProductQty($product, (int) $product->stock_qty);
        });
    }

    public function branchStocks(): HasMany
    {
        return $this->hasMany(BranchStock::class);
    }

    protected function casts(): array
    {
        return [
            'images' => 'array',
            'price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'active' => 'boolean',
        ];
    }

    protected function imageUrls(): Attribute
    {
        return Attribute::get(fn () => collect($this->images ?? [])
            ->map(fn (string $path) => 'storage/'.$path)
            ->values()
            ->all());
    }

    public function takeStock(int $quantity, ?int $branchId = null): void
    {
        BranchInventory::takeProduct($this, $quantity, $branchId);
    }

    public function returnStock(int $quantity, ?int $branchId = null): void
    {
        BranchInventory::returnProduct($this, $quantity, $branchId);
    }
}
