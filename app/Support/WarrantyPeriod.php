<?php

namespace App\Support;

use Illuminate\Support\Carbon;

class WarrantyPeriod
{
    /**
     * @return array{warranty_months: int|null, warranty_starts_on: string|null, warranty_until: string|null}
     */
    public static function resolve(?string $startsOn, mixed $months, ?string $until, mixed $purchaseDate = null): array
    {
        $start = Carbon::parse($startsOn ?: $purchaseDate ?: today())->startOfDay();
        $cover = $months !== null && $months !== '' ? (int) $months : 0;

        if ($cover <= 0 && blank($until)) {
            return [
                'warranty_months' => null,
                'warranty_starts_on' => null,
                'warranty_until' => null,
            ];
        }

        $end = $until
            ? Carbon::parse($until)->startOfDay()
            : $start->copy()->addMonths(max($cover, 1));

        if ($end->lt($start)) {
            $end = $start->copy();
        }

        return [
            'warranty_months' => $cover > 0 ? $cover : max(1, (int) round($start->diffInMonths($end))),
            'warranty_starts_on' => $start->toDateString(),
            'warranty_until' => $end->toDateString(),
        ];
    }
}
