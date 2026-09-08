<?php

namespace App\Support;

class AppLocale
{
    public const ENGLISH = 'en';

    public const SINHALA = 'si';

    /** @return list<string> */
    public static function codes(): array
    {
        return [self::ENGLISH, self::SINHALA];
    }

    public static function normalize(?string $locale): string
    {
        $locale = strtolower(trim((string) $locale));

        if (in_array($locale, ['si', 'sin', 'sinhala'], true)) {
            return self::SINHALA;
        }

        return self::ENGLISH;
    }
}
