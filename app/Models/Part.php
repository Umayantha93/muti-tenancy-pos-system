<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Services\BranchInventory;
use App\Services\PartBarcode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'sku', 'barcode', 'brand', 'type', 'subcategory', 'model', 'year', 'price', 'cost_price', 'stock_qty', 'stock_unit', 'serialized', 'images', 'description'])]
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
            BranchInventory::seedPart($part, (float) $part->stock_qty);
        });

        static::saved(function (Part $part): void {
            if (BranchInventory::$mutating || ! $part->wasChanged('stock_qty')) {
                return;
            }
            BranchInventory::setPartQty($part, (float) $part->stock_qty);
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
            'pending_price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'serialized' => 'boolean',
        ];
    }

    protected function stockQty(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value): int|float {
                $n = round((float) $value, 3);

                return fmod($n, 1.0) == 0.0 ? (int) $n : $n;
            },
        );
    }

    protected function imageUrls(): Attribute
    {
        return Attribute::get(fn () => collect($this->images ?? [])
            ->map(fn (string $path) => 'storage/'.$path)
            ->values()
            ->all());
    }

    /**
     * Split a sale across the old labelled price and the price waiting after that stock sells.
     * Prices are taken before stock is reduced.
     *
     * @return list<array{quantity: float, unit_price: float}>
     */
    public function salePriceLayers(float|int $quantity): array
    {
        $quantity = round(max(0, (float) $quantity), 3);
        $current = round((float) $this->price, 2);
        if ($quantity <= 0 || $this->pending_price === null || $this->pending_price_at_qty === null) {
            return [['quantity' => $quantity, 'unit_price' => $current]];
        }

        $stock = round((float) $this->getRawOriginal('stock_qty'), 3);
        $oldLeft = max(0, round($stock - (float) $this->pending_price_at_qty, 3));
        $next = round((float) $this->pending_price, 2);
        if ($oldLeft <= 0.0001) {
            return [['quantity' => $quantity, 'unit_price' => $next]];
        }
        if ($quantity <= $oldLeft + 0.0001) {
            return [['quantity' => $quantity, 'unit_price' => $current]];
        }

        return [
            ['quantity' => $oldLeft, 'unit_price' => $current],
            ['quantity' => round($quantity - $oldLeft, 3), 'unit_price' => $next],
        ];
    }

    /**
     * A scheduled price waits until only the stock received with it is left
     * (total stock across shops falls to pending_price_at_qty or below).
     */
    public function applyPendingPriceIfDue(): void
    {
        if ($this->pending_price === null || $this->pending_price_at_qty === null) {
            return;
        }
        if ((float) $this->getRawOriginal('stock_qty') > (float) $this->pending_price_at_qty + 0.0001) {
            return;
        }
        $this->forceFill([
            'price' => $this->pending_price,
            'pending_price' => null,
            'pending_price_at_qty' => null,
        ])->saveQuietly();
    }

    public function takeStock(float|int $quantity, ?int $branchId = null): void
    {
        BranchInventory::takePart($this, $quantity, $branchId);
    }

    public function returnStock(float|int $quantity, ?int $branchId = null): void
    {
        BranchInventory::returnPart($this, $quantity, $branchId);
    }
}
