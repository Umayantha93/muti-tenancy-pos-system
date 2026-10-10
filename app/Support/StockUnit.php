<?php

namespace App\Support;

class StockUnit
{
    public const QTY = 'qty';

    public const ML = 'ml';

    public const L = 'l';

    public const G = 'g';

    public const KG = 'kg';

    public const MG = 'mg';

    public const M = 'm';

    public const CM = 'cm';

    public const PACK = 'pack';

    public const BOX = 'box';

    public const PAIR = 'pair';

    public const DOZEN = 'dozen';

    /**
     * Units sold and stocked in fractions (0.1, 0.2, …).
     *
     * @return list<string>
     */
    public static function measured(): array
    {
        return [self::ML, self::L, self::G, self::KG, self::MG, self::M, self::CM];
    }

    /**
     * @return list<string>
     */
    public static function allowed(): array
    {
        return [
            self::QTY,
            self::ML,
            self::L,
            self::G,
            self::KG,
            self::MG,
            self::M,
            self::CM,
            self::PACK,
            self::BOX,
            self::PAIR,
            self::DOZEN,
        ];
    }

    public static function defaultForBusiness(?string $type): string
    {
        return $type === BusinessTypes::PAINT ? self::ML : self::QTY;
    }

    public static function normalize(?string $unit, ?string $businessType = null): string
    {
        $parsed = self::tryFromImport($unit);
        if ($parsed !== null) {
            return $parsed;
        }

        return self::defaultForBusiness($businessType);
    }

    public static function tryFromImport(?string $unit): ?string
    {
        $unit = strtolower(trim((string) $unit));
        $unit = str_replace(['.', ' '], '', $unit);

        return match ($unit) {
            'item', 'items', 'qty', 'quantity', 'pcs', 'pc', 'piece', 'pieces', 'nos', 'no' => self::QTY,
            'l', 'lt', 'ltr', 'litre', 'liter', 'litres', 'liters' => self::L,
            'ml', 'millilitre', 'milliliter', 'millilitres', 'milliliters' => self::ML,
            'g', 'gm', 'gms', 'gram', 'grams' => self::G,
            'kg', 'kgs', 'kilo', 'kilos', 'kilogram', 'kilograms' => self::KG,
            'mg', 'milligram', 'milligrams' => self::MG,
            'm', 'meter', 'metre', 'meters', 'metres' => self::M,
            'cm', 'centimeter', 'centimetre', 'centimeters', 'centimetres' => self::CM,
            'pack', 'packs', 'packet', 'packets', 'pkt' => self::PACK,
            'box', 'boxes' => self::BOX,
            'pair', 'pairs' => self::PAIR,
            'dozen', 'doz', 'dz' => self::DOZEN,
            default => null,
        };
    }

    public static function isVolume(string $unit): bool
    {
        return in_array(self::normalize($unit), [self::ML, self::L], true);
    }

    public static function isWeight(string $unit): bool
    {
        return in_array(self::normalize($unit), [self::MG, self::G, self::KG], true);
    }

    public static function allowsDecimal(?string $unit, ?string $businessType = null): bool
    {
        return in_array(self::normalize($unit, $businessType), self::measured(), true);
    }

    public static function quantityError(?string $unit, float|int $quantity, ?string $businessType = null): ?string
    {
        if (self::allowsDecimal($unit, $businessType) || self::isWhole($quantity)) {
            return null;
        }

        return self::label($unit, $businessType).' stock must be a whole number.';
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

        $fromGrams = self::toGrams((float) $quantity, $from);
        if ($fromGrams !== null && self::isWeight($to)) {
            return round(self::fromGrams($fromGrams, $to), 3);
        }

        return round((float) $quantity, 3);
    }

    public static function label(?string $unit, ?string $businessType = null): string
    {
        return match (self::normalize($unit, $businessType)) {
            self::ML => 'ML',
            self::L => 'L',
            self::G => 'G',
            self::KG => 'KG',
            self::MG => 'MG',
            self::M => 'M',
            self::CM => 'CM',
            self::PACK => 'PACK',
            self::BOX => 'BOX',
            self::PAIR => 'PAIR',
            self::DOZEN => 'DOZEN',
            default => 'ITEM',
        };
    }

    public static function importList(): string
    {
        return implode(', ', array_map(fn (string $unit) => self::label($unit), self::allowed()));
    }

    private static function toGrams(float $quantity, string $unit): ?float
    {
        return match ($unit) {
            self::MG => $quantity / 1000,
            self::G => $quantity,
            self::KG => $quantity * 1000,
            default => null,
        };
    }

    private static function fromGrams(float $grams, string $unit): float
    {
        return match ($unit) {
            self::MG => $grams * 1000,
            self::KG => $grams / 1000,
            default => $grams,
        };
    }
};
