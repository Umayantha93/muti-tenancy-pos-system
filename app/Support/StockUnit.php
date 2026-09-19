<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

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
        if ($businessType === BusinessTypes::PAINT) {
            return self::ML;
        }

        $unit = strtolower(trim((string) $unit));
        if ($unit === 'litre' || $unit === 'liter' || $unit === 'litres' || $unit === 'liters') {
            $unit = self::L;
        }

        return in_array($unit, self::allowed(), true)
            ? $unit
            : self::defaultForBusiness($businessType);
    }

    public static function isVolume(string $unit): bool
    {
        return in_array(self::normalize($unit), [self::ML, self::L], true);
    }

    public static function convertQuantity(int $quantity, string $from, string $to): int
    {
        $from = self::normalize($from);
        $to = self::normalize($to);
        if ($from === $to) {
            return $quantity;
        }
        if ($from === self::L && $to === self::ML) {
            return $quantity * 1000;
        }
        if ($from === self::ML && $to === self::L) {
            if ($quantity % 1000 !== 0) {
                throw ValidationException::withMessages([
                    'quantity' => ['This item is stocked in litres. Enter whole litres, or switch the unit to ml.'],
                ]);
            }

            return intdiv($quantity, 1000);
        }

        return $quantity;
    }

    public static function label(?string $unit, ?string $businessType = null): string
    {
        return match (self::normalize($unit, $businessType)) {
            self::ML => 'ml',
            self::L => 'L',
            default => 'qty',
        };
    }
}
