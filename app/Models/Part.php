<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Services\BranchInventory;
use App\Services\PartBarcode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'sku', 'barcode', 'brand', 'type', 'model', 'year', 'price', 'cost_price', 'stock_qty', 'stock_unit', 'serialized', 'images', 'description'])]
class Part extends Model
{
    use BelongsToTenant;

    protected $appends = ['image_urls'];

    protected static function booted(): void
    {
        static::creating(function (Part $part): void {
            $barcode = trim((string) ($part->barcode ?? ''));
            if ($barcode === '') {
                $part->barcode = PartBarcode::uniqueForTenant(
                    $part->tenant_id ? (int) $part->tenant_id : null
                );
            }
        });

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

    public function serials(): HasMany
    {
        return $this->hasMany(PartSerial::class);
    }

    protected function casts(): array
    {
        return [
            'images' => 'array',
            'price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'serialized' => 'boolean',
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
