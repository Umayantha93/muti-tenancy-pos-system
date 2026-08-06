<?php

namespace App\Support;

class BusinessTypes
{
    public const GARAGE = 'garage';

    public const PHOTOGRAPHY = 'photography';

    public const CLOTHING = 'clothing';

    public const COTTAGE = 'cottage';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::GARAGE, self::PHOTOGRAPHY, self::CLOTHING, self::COTTAGE];
    }

    /**
     * @return array<string, list<string>>
     */
    public static function featureMatrix(): array
    {
        $shared = ['customers', 'billing', 'employees_management', 'attendance', 'payroll', 'balance_sheet', 'reports'];

        return [
            self::GARAGE => array_merge(['admit_vehicle', 'parts_inventory'], $shared),
            self::PHOTOGRAPHY => array_merge(['photo_bookings', 'photo_packages'], $shared),
            self::CLOTHING => array_merge(['retail_pos', 'product_catalog'], $shared),
            self::COTTAGE => array_merge(['cottage_rooms', 'cottage_stays'], $shared),
        ];
    }

    /**
     * @return list<string>
     */
    public static function defaults(string $type): array
    {
        return self::featureMatrix()[$type] ?? ['customers', 'billing', 'balance_sheet', 'reports'];
    }

    /**
     * @return list<string>
     */
    public static function featuresForType(?string $type): array
    {
        if ($type && isset(self::featureMatrix()[$type])) {
            return self::featureMatrix()[$type];
        }

        return array_values(array_unique(array_merge(...array_values(self::featureMatrix()))));
    }

    public static function billPrefix(string $type): string
    {
        return match ($type) {
            self::PHOTOGRAPHY => 'ORD',
            self::CLOTHING => 'SALE',
            self::COTTAGE => 'STAY',
            default => 'JOB',
        };
    }

    public static function normalizeLegacy(string $type): string
    {
        return match ($type) {
            'shop', 'supermarket' => self::CLOTHING,
            default => in_array($type, self::all(), true) ? $type : self::GARAGE,
        };
    }
}
