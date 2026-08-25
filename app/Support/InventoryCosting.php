<?php

namespace App\Support;

class InventoryCosting
{
    /**
     * Weighted average unit cost after adding stock to an existing on-hand quantity.
     * Purchase expense still uses the new buy's unit cost × qty; only the catalogue cost_price blends.
     */
    public static function weightedAverageCost(
        float|int $onHandQty,
        float|int|string|null $onHandUnitCost,
        float|int $addedQty,
        float|int|string|null $addedUnitCost,
    ): float {
        $onHand = max(0, (float) $onHandQty);
        $added = max(0, (float) $addedQty);
        $oldCost = round((float) ($onHandUnitCost ?? 0), 2);
        $newCost = round((float) ($addedUnitCost ?? 0), 2);

        if ($added <= 0) {
            return $oldCost;
        }

        if ($onHand <= 0) {
            return $newCost;
        }

        $totalQty = $onHand + $added;

        return round((($onHand * $oldCost) + ($added * $newCost)) / $totalQty, 2);
    }
}
