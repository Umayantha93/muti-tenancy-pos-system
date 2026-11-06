<?php

namespace App\Support;

class ClothingSizes
{
    /** @var list<string> */
    public const APPAREL = ['XS', 'S', 'M', 'L', 'XL', 'XXL'];

    /** @var list<string> */
    public const JEANS = ['28', '30', '32', '34', '36', '38', '40', '42'];

    /** @var list<string> */
    public const FOOTWEAR = ['EU 39', 'EU 40', 'EU 41', 'EU 42', 'EU 43'];

    /**
     * @return list<string>
     */
    public static function forScale(string $scale): array
    {
        return match ($scale) {
            'jeans' => self::JEANS,
            'footwear' => self::FOOTWEAR,
            'one' => ['One'],
            default => self::APPAREL,
        };
    }

    public static function scaleOf(?string $size): string
    {
        $size = strtoupper(trim((string) $size));
        if (in_array($size, self::APPAREL, true)) {
            return 'apparel';
        }
        if (in_array($size, self::JEANS, true)) {
            return 'jeans';
        }
        if ($size === 'ONE') {
            return 'one';
        }
        if (str_starts_with($size, 'EU ')) {
            return 'footwear';
        }

        return 'other';
    }
}
