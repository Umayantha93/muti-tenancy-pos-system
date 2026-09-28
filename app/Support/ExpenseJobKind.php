<?php

namespace App\Support;

use App\Models\User;

class ExpenseJobKind
{
    public const REPAIR = 'repair';

    public const SERVICE = 'service';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::REPAIR, self::SERVICE];
    }

    public static function label(?string $kind): ?string
    {
        return match ($kind) {
            self::REPAIR => 'Repair',
            self::SERVICE => 'Service',
            default => null,
        };
    }

    public static function enabledFor(?User $user): bool
    {
        return $user !== null
            && $user->tenant?->business_type === BusinessTypes::GARAGE
            && $user->canAccessFeature('expense_job_split');
    }

    public static function exportEnabledFor(?User $user): bool
    {
        return $user !== null
            && $user->tenant?->business_type === BusinessTypes::GARAGE
            && $user->canAccessFeature('finance_report_export');
    }
}
