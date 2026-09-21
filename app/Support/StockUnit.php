<?php

namespace App\Support;

class StockUnit
{
    public const QTY = 'qty';

    public const ML = 'ml';

    public const L = 'l';

    /**
     * @return list<string>
     */
    public static function allowed(): array
    {
        return [self::QTY, self::ML, self::L];
    }

    public static function defaultForBusiness(?string $type): string
    {
        return $type === BusinessTypes::PAINT ? self::ML : self::QTY;
    }

    public static function normalize(?string $unit, ?string $businessType = null): string
    {
        $parsed = self::tryFromImport($unit);
        if ($parsed !== null) {
            return $businessType === BusinessTypes::PAINT ? self::ML : $parsed;
        }

        return self::defaultForBusiness($businessType);
    }

    public static function tryFromImport(?string $unit): ?string
    {
        $unit = strtolower(trim((string) $unit));
        $unit = str_replace(['.', ' '], '', $unit);

        return match ($unit) {
            '', 'item', 'items', 'qty', 'quantity', 'pcs', 'pc', 'piece', 'pieces' => self::QTY,
            'l', 'lt', 'ltr', 'litre', 'liter', 'litres', 'liters' => self::L,
            'ml', 'millilitre', 'milliliter', 'millilitres', 'milliliters' => self::ML,
            default => null,
        };
    }

    public static function isVolume(string $unit): bool
    {
        return in_array(self::normalize($unit), [self::ML, self::L], true);
    }

    public static function allowsDecimal(?string $unit, ?string $businessType = null): bool
    {
        return self::isVolume(self::normalize($unit, $businessType));
    }

    public static function isWhole(float|int $quantity): bool
    {
        $qty = (float) $quantity;

        return abs($qty - round($qty)) < 0.0005;
    }

    public static function convertQuantity(float|int $quantity, string $from, string $to): float
    {
        $from = self::normalize($from);
        $to = self::normalize($to);
        if ($from === $to) {
            return round((float) $quantity, 3);
        }
        if ($from === self::L && $to === self::ML) {
            return round((float) $quantity * 1000, 3);
        }
        if ($from === self::ML && $to === self::L) {
            return round((float) $quantity / 1000, 3);
        }

        return round((float) $quantity, 3);
    }

    public static function label(?string $unit, ?string $businessType = null): string
    {
        return match (self::normalize($unit, $businessType)) {
            self::ML => 'ML',
            self::L => 'L',
            default => 'ITEM',
        };
    }
}
