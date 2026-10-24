<?php

namespace App\Services\Commission;

use App\Models\Tenant;

class CommissionSchemes
{
    public const LABOR_EXCESS = 'labor_excess';

    public const LINE_PERCENT = 'line_percent';

    /**
     * @return list<CommissionScheme>
     */
    public static function all(): array
    {
        return [
            new LaborExcessCommission,
            new LinePercentCommission,
        ];
    }

    /**
     * @return list<array{key: string, label: string, summary: string, rules: array<string, bool|int|float>}>
     */
    public static function catalog(): array
    {
        return array_map(fn (CommissionScheme $scheme) => [
            'key' => $scheme->key(),
            'label' => $scheme->label(),
            'summary' => $scheme->summary(),
            'rules' => $scheme->rules(),
        ], self::all());
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_map(fn (CommissionScheme $scheme) => $scheme->key(), self::all());
    }

    public static function for(?Tenant $tenant): CommissionScheme
    {
        return match ($tenant?->commission_type) {
            self::LINE_PERCENT => new LinePercentCommission,
            default => new LaborExcessCommission,
        };
    }

    /**
     * @return array<string, bool|int|float>
     */
    public static function rulesFor(?Tenant $tenant): array
    {
        return self::for($tenant)->rules();
    }
}
