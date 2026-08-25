<?php

namespace Tests\Unit;

use App\Support\InventoryCosting;
use PHPUnit\Framework\TestCase;

class InventoryCostingTest extends TestCase
{
    public function test_weighted_average_blends_old_and_new_stock(): void
    {
        // 5 left @ 2100 + buy 10 @ 2200 => 32500 / 15 = 2166.67
        $this->assertSame(
            2166.67,
            InventoryCosting::weightedAverageCost(5, 2100, 10, 2200),
        );
    }

    public function test_equal_qty_different_costs_midpoint(): void
    {
        // 10 @ 2100 + 10 @ 2200 => 2150
        $this->assertSame(
            2150.0,
            InventoryCosting::weightedAverageCost(10, 2100, 10, 2200),
        );
    }

    public function test_empty_shelf_uses_new_cost_only(): void
    {
        $this->assertSame(
            2200.0,
            InventoryCosting::weightedAverageCost(0, 2100, 10, 2200),
        );
    }

    public function test_zero_added_keeps_old_cost(): void
    {
        $this->assertSame(
            2100.0,
            InventoryCosting::weightedAverageCost(10, 2100, 0, 2200),
        );
    }
}
