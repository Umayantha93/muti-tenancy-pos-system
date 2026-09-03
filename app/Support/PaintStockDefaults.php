<?php

namespace App\Support;

use App\Models\Part;

class PaintStockDefaults
{
    /**
     * Starter color-stock rows in millilitres. Not a car-color catalog.
     *
     * @return list<array{name: string, brand: string, type: string, price: float, cost_price: float, stock_qty: int, description: string}>
     */
    public static function catalog(): array
    {
        return [
            [
                'name' => '2K primer grey',
                'brand' => 'Shop stock',
                'type' => 'Primer',
                'price' => 22,
                'cost_price' => 12,
                'stock_qty' => 3200,
                'description' => 'Stocked in millilitres',
            ],
            [
                'name' => 'HS clear coat',
                'brand' => 'Shop stock',
                'type' => 'Clear',
                'price' => 38,
                'cost_price' => 20,
                'stock_qty' => 5000,
                'description' => 'Stocked in millilitres',
            ],
            [
                'name' => 'Thinner 2K',
                'brand' => 'Shop stock',
                'type' => 'Thinner',
                'price' => 8,
                'cost_price' => 4,
                'stock_qty' => 8000,
                'description' => 'Stocked in millilitres',
            ],
            [
                'name' => '2K hardener',
                'brand' => 'Shop stock',
                'type' => 'Hardener',
                'price' => 18,
                'cost_price' => 10,
                'stock_qty' => 2000,
                'description' => 'Stocked in millilitres',
            ],
        ];
    }

    public static function seedFor(int $tenantId): void
    {
        if (Part::withoutGlobalScopes()->where('tenant_id', $tenantId)->exists()) {
            return;
        }

        foreach (static::catalog() as $row) {
            $part = new Part;
            $part->forceFill([
                'tenant_id' => $tenantId,
                ...$row,
            ])->save();
        }
    }
}
