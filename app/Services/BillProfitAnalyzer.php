<?php

namespace App\Services;

use App\Models\Bill;
use App\Support\BusinessTypes;

class BillProfitAnalyzer
{
    /**
     * @return array{
     *   revenue: float,
     *   cogs: float,
     *   profit: float,
     *   margin: float,
     *   billing_type: string,
     *   payment_status: string,
     *   lines: list<array<string, mixed>>
     * }
     */
    public function summarize(Bill $bill): array
    {
        $bill->loadMissing(['items.part', 'customer', 'vehicle', 'payments']);

        $discounts = 0.0;
        $cogs = 0.0;
        $lines = [];

        foreach ($bill->items as $item) {
            $quantity = (float) $item->quantity;
            $isDiscount = in_array($item->type, BusinessTypes::discountItemTypes(), true);
            $unitCost = 0.0;

            if ($item->type === 'part') {
                $unitCost = (float) ($item->purchase_unit_cost ?? $item->part?->cost_price ?? 0);
            }

            $lineCogs = round($unitCost * $quantity, 2);
            $lineRevenue = $isDiscount ? 0.0 : (float) $item->line_total;
            if ($isDiscount) {
                $discounts += (float) $item->line_total;
            }
            $cogs += $lineCogs;

            $lines[] = [
                'id' => $item->id,
                'type' => $item->type,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'purchase_unit_cost' => $item->type === 'part' ? number_format($unitCost, 2, '.', '') : null,
                'line_total' => $item->line_total,
                'cogs' => $lineCogs,
                'profit' => $isDiscount
                    ? round(-1 * (float) $item->line_total, 2)
                    : round($lineRevenue - $lineCogs, 2),
            ];
        }

        $revenue = max(0.0, round((float) $bill->subtotal - $discounts, 2));
        $cogs = round($cogs, 2);
        $profit = round($revenue - $cogs, 2);

        return [
            'revenue' => $revenue,
            'cogs' => $cogs,
            'profit' => $profit,
            'margin' => $revenue > 0 ? round(($profit / $revenue) * 100, 1) : 0.0,
            'billing_type' => $bill->owe_in_due_date ? 'credit' : 'instant',
            'payment_status' => $this->paymentStatus($bill),
            'job_kind' => $bill->job_kind === Bill::JOB_KIND_SERVICE ? Bill::JOB_KIND_SERVICE : Bill::JOB_KIND_REPAIR,
            'lines' => $lines,
        ];
    }

    public function paymentStatus(Bill $bill): string
    {
        return match ($bill->status) {
            'closed', 'paid' => 'paid',
            'owe_in' => 'credit',
            'partially_paid' => 'partial',
            default => 'unpaid',
        };
    }
}
