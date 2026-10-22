<?php

namespace App\Support;

class InventoryCosting
{
    public const AVERAGE = 'average';

    public const LATEST = 'latest';

    public static function normalize(?string $method): string
    {
        return $method === self::LATEST ? self::LATEST : self::AVERAGE;
    }

    /**
     * Catalogue cost after a receipt.
     * average: (old qty × old cost + new qty × new cost) / total qty.
     * latest: the receipt's unit cost replaces the catalogue cost. Free gifts keep the previous cost.
     */
    public static function catalogueCost(
        ?string $method,
        float|int $onHandQty,
        float|int|string|null $onHandUnitCost,
        float|int $addedQty,
        float|int|string|null $addedUnitCost,
        bool $freeGift = false,
    ): float {
        if (self::normalize($method) === self::LATEST) {
            $previous = round((float) ($onHandUnitCost ?? 0), 2);
            if ($freeGift || max(0, (float) $addedQty) <= 0) {
                return $previous;
            }

            return round((float) ($addedUnitCost ?? 0), 2);
        }

        return self::weightedAverageCost($onHandQty, $onHandUnitCost, $addedQty, $addedUnitCost);
    }

    /**
     * Catalogue cost after a receipt line is corrected.
     * Average removes the old line value from the on-hand average and adds the new one.
     * Latest replaces the catalogue cost with the corrected unit cost. A zero cost leaves it unchanged.
     */
    public static function correctedCatalogueCost(
        ?string $method,
        float|int $onHandQty,
        float|int|string|null $onHandUnitCost,
        float|int $oldQty,
        float|int|string|null $oldUnitCost,
        float|int $newQty,
        float|int|string|null $newUnitCost,
    ): float {
        $onHand = max(0, (float) $onHandQty);
        $old = max(0, (float) $oldQty);
        $new = max(0, (float) $newQty);
        $current = round((float) ($onHandUnitCost ?? 0), 2);
        $previousLine = round((float) ($oldUnitCost ?? 0), 2);
        $nextLine = round((float) ($newUnitCost ?? 0), 2);

        if (self::normalize($method) === self::LATEST) {
            return $nextLine > 0 ? $nextLine : $current;
        }

        $nextQty = $onHand - $old + $new;
        if ($nextQty <= 0.0001) {
            return $nextLine;
        }

        $value = ($onHand * $current) - ($old * $previousLine) + ($new * $nextLine);

        return round(max(0, $value) / $nextQty, 2);
    }

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
