<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Services\BranchInventory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'sku', 'barcode', 'brand', 'type', 'model', 'year', 'price', 'cost_price', 'stock_qty', 'images', 'description'])]
class Part extends Model
{
    use BelongsToTenant;

    protected $appends = ['image_urls'];

    protected static function booted(): void
    {
        static::created(function (Part $part): void {
            if (BranchInventory::$mutating) {
                return;
            }
            BranchInventory::seedPart($part, (int) $part->stock_qty);
        });

        static::saved(function (Part $part): void {
            if (BranchInventory::$mutating || $part->wasRecentlyCreated || ! $part->wasChanged('stock_qty')) {
                return;
            }
            BranchInventory::setPartQty($part, (int) $part->stock_qty);
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
        BranchInventory::takePart($this, $quantity, $branchId);
    }

    public function returnStock(int $quantity, ?int $branchId = null): void
    {
        BranchInventory::returnPart($this, $quantity, $branchId);
    }
}
