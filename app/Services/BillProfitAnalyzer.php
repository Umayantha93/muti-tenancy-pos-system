<?php

namespace App\Services;

use App\Models\Bill;
use App\Models\BillRefundItem;
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
     *   refund_status: string,
     *   amount_refunded: float,
     *   work_revenue: float,
     *   work_cogs: float,
     *   work_profit: float,
     *   goods_revenue: float,
     *   goods_cogs: float,
     *   goods_profit: float,
     *   lines: list<array<string, mixed>>
     * }
     */
    public function summarize(Bill $bill): array
    {
        $figures = $this->lineFigures($bill);
        $revenue = $bill->is_free ? 0.0 : max(0.0, round((float) $bill->subtotal - $figures['discounts'] - $figures['refunded_revenue'], 2));
        $cogs = round($figures['cogs'], 2);
        $profit = round($revenue - $cogs, 2);
        $activity = $this->allocateActivity($this->activityFromLines($figures['lines']), $revenue, $cogs);

        return [
            'revenue' => $revenue,
            'cogs' => $cogs,
            'profit' => $profit,
            'margin' => $revenue > 0 ? round(($profit / $revenue) * 100, 1) : 0.0,
            'billing_type' => $bill->owe_in_due_date ? 'credit' : 'instant',
            'payment_status' => $this->paymentStatus($bill),
            'refund_status' => $bill->resolvedRefundStatus(),
            'amount_refunded' => round((float) $bill->amount_refunded, 2),
            'job_kind' => match ($bill->job_kind) {
                Bill::JOB_KIND_SERVICE => Bill::JOB_KIND_SERVICE,
                Bill::JOB_KIND_PARTS_SALE => Bill::JOB_KIND_PARTS_SALE,
                Bill::JOB_KIND_BOTH => Bill::JOB_KIND_BOTH,
                default => Bill::JOB_KIND_REPAIR,
            },
            ...$activity,
            'lines' => $figures['lines'],
        ];
    }

    /**
     * Work is labor and other charges. Goods are parts and products the shop sold.
     * A customer-supplied part stays with work and has no sale and no cost.
     *
     * @return array{work_revenue: float, work_cogs: float, goods_revenue: float, goods_cogs: float}
     */
    public function activitySplit(Bill $bill, ?string $section = null): array
    {
        $figures = $this->lineFigures($bill);

        return $this->activityFromLines($figures['lines'], $section);
    }

    /**
     * Share the bill totals across work and goods in proportion to each side's charges.
     * The two profits add up to revenue minus cost.
     *
     * @param  array{work_revenue: float, work_cogs: float, goods_revenue: float, goods_cogs: float}  $natural
     * @return array{work_revenue: float, work_cogs: float, work_profit: float, goods_revenue: float, goods_cogs: float, goods_profit: float}
     */
    public function allocateActivity(array $natural, float $targetRevenue, float $targetCogs): array
    {
        $targetRevenue = round($targetRevenue, 2);
        $targetCogs = round($targetCogs, 2);
        $workRevenue = (float) $natural['work_revenue'];
        $goodsRevenue = (float) $natural['goods_revenue'];
        $workCogs = (float) $natural['work_cogs'];
        $goodsCogs = (float) $natural['goods_cogs'];
        $revenueBase = $workRevenue + $goodsRevenue;

        if ($revenueBase > 0) {
            $workNet = round($targetRevenue * ($workRevenue / $revenueBase), 2);
            $goodsNet = round($targetRevenue - $workNet, 2);
        } elseif ($goodsCogs > 0 && $workCogs <= 0) {
            $workNet = 0.0;
            $goodsNet = $targetRevenue;
        } else {
            $workNet = $targetRevenue;
            $goodsNet = 0.0;
        }

        $cogsBase = $workCogs + $goodsCogs;
        if ($cogsBase > 0) {
            $workCost = round($targetCogs * ($workCogs / $cogsBase), 2);
            $goodsCost = round($targetCogs - $workCost, 2);
        } else {
            $workCost = 0.0;
            $goodsCost = $targetCogs;
        }

        $workProfit = round($workNet - $workCost, 2);
        $goodsProfit = round(round($targetRevenue - $targetCogs, 2) - $workProfit, 2);

        return [
            'work_revenue' => $workNet,
            'work_cogs' => $workCost,
            'work_profit' => $workProfit,
            'goods_revenue' => $goodsNet,
            'goods_cogs' => $goodsCost,
            'goods_profit' => $goodsProfit,
        ];
    }

    /**
     * Labor and service stay in their section. Discounts are shared by section revenue.
     *
     * @return array{service: array{revenue: float, cogs: float, profit: float}, repair: array{revenue: float, cogs: float, profit: float}}
     */
    public function sectionSplit(Bill $bill): array
    {
        $bill->loadMissing(['items.part']);
        $serviceRevenue = 0.0;
        $repairRevenue = 0.0;
        $serviceCogs = 0.0;
        $repairCogs = 0.0;
        $discounts = 0.0;

        foreach ($bill->items as $item) {
            if (in_array($item->type, BusinessTypes::discountItemTypes(), true)) {
                $discounts += (float) $item->line_total;
                continue;
            }
            $revenue = $bill->is_free || $item->is_free ? 0.0 : (float) $item->line_total;
            $cogs = in_array($item->type, ['part', 'product'], true)
                ? (float) ($item->purchase_unit_cost ?? $item->part?->cost_price ?? 0) * (float) $item->quantity
                : 0.0;
            $section = $item->section ?: match ($item->type) {
                'service_addon', 'service', 'charge' => 'service',
                'labor' => 'repair',
                default => $bill->job_kind === Bill::JOB_KIND_SERVICE ? 'service' : 'repair',
            };
            if ($section === 'service') {
                $serviceRevenue += $revenue;
                $serviceCogs += $cogs;
            } else {
                $repairRevenue += $revenue;
                $repairCogs += $cogs;
            }
        }

        $base = $serviceRevenue + $repairRevenue;
        $serviceDiscount = $base > 0 ? $discounts * ($serviceRevenue / $base) : 0.0;
        $repairDiscount = $discounts - $serviceDiscount;
        $serviceNet = max(0.0, round($serviceRevenue - $serviceDiscount, 2));
        $repairNet = max(0.0, round($repairRevenue - $repairDiscount, 2));

        return [
            'service' => [
                'revenue' => $serviceNet,
                'cogs' => round($serviceCogs, 2),
                'profit' => round($serviceNet - $serviceCogs, 2),
            ],
            'repair' => [
                'revenue' => $repairNet,
                'cogs' => round($repairCogs, 2),
                'profit' => round($repairNet - $repairCogs, 2),
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array{work_revenue: float, work_cogs: float, goods_revenue: float, goods_cogs: float}
     */
    private function activityFromLines(array $lines, ?string $section = null): array
    {
        $workRevenue = 0.0;
        $goodsRevenue = 0.0;
        $workCogs = 0.0;
        $goodsCogs = 0.0;

        foreach ($lines as $line) {
            if (($line['bucket'] ?? '') === 'discount') {
                continue;
            }
            if ($section !== null && ($line['section'] ?? '') !== $section) {
                continue;
            }
            if (($line['bucket'] ?? '') === 'goods') {
                $goodsRevenue += (float) ($line['revenue'] ?? 0);
                $goodsCogs += (float) ($line['cogs'] ?? 0);
            } else {
                $workRevenue += (float) ($line['revenue'] ?? 0);
                $workCogs += (float) ($line['cogs'] ?? 0);
            }
        }

        return [
            'work_revenue' => round($workRevenue, 2),
            'work_cogs' => round($workCogs, 2),
            'goods_revenue' => round($goodsRevenue, 2),
            'goods_cogs' => round($goodsCogs, 2),
        ];
    }

    /**
     * @return array{lines: list<array<string, mixed>>, discounts: float, refunded_revenue: float, cogs: float}
     */
    private function lineFigures(Bill $bill): array
    {
        $bill->loadMissing(['items.part', 'refunds.items']);

        $refundMeta = [];
        foreach ($bill->refunds as $refund) {
            foreach ($refund->items as $refundItem) {
                $id = (int) $refundItem->bill_item_id;
                $refundMeta[$id]['revenue'] = ($refundMeta[$id]['revenue'] ?? 0.0) + (float) $refundItem->amount;
                $refundMeta[$id]['qty'] = ($refundMeta[$id]['qty'] ?? 0.0) + (float) $refundItem->quantity;
                if ($refundItem->disposition === BillRefundItem::DISPOSITION_RESTOCK) {
                    $refundMeta[$id]['restock_qty'] = ($refundMeta[$id]['restock_qty'] ?? 0.0) + (float) $refundItem->quantity;
                }
            }
        }

        $discounts = 0.0;
        $cogs = 0.0;
        $refundedRevenue = 0.0;
        $lines = [];

        foreach ($bill->items as $item) {
            $quantity = (float) $item->quantity;
            $isDiscount = in_array($item->type, BusinessTypes::discountItemTypes(), true);
            $unitCost = 0.0;
            if (in_array($item->type, ['part', 'product'], true)) {
                $unitCost = (float) ($item->purchase_unit_cost ?? $item->part?->cost_price ?? 0);
            }

            $itemRefund = $refundMeta[$item->id] ?? [];
            $lineRefundRevenue = round((float) ($itemRefund['revenue'] ?? 0), 2);
            $restockQty = (float) ($itemRefund['restock_qty'] ?? 0);
            $refundedQty = (float) ($itemRefund['qty'] ?? 0);
            $lineCogs = round($unitCost * max(0.0, $quantity - $restockQty), 2);
            $free = (bool) $bill->is_free || (bool) $item->is_free;
            $lineRevenue = ($free || $isDiscount) ? 0.0 : max(0.0, round((float) $item->line_total - $lineRefundRevenue, 2));
            if ($isDiscount && ! $free) {
                $discounts += (float) $item->line_total;
            } elseif (! $free) {
                $refundedRevenue += $lineRefundRevenue;
            }
            $cogs += $lineCogs;

            $lines[] = [
                'id' => $item->id,
                'type' => $item->type,
                'bucket' => $isDiscount ? 'discount' : (in_array($item->type, ['part', 'product'], true) ? 'goods' : 'work'),
                'section' => $isDiscount ? 'discount' : ($item->section ?: match ($item->type) {
                    'service_addon', 'service', 'charge' => 'service',
                    'labor' => 'repair',
                    default => $bill->job_kind === Bill::JOB_KIND_SERVICE ? 'service' : 'repair',
                }),
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'purchase_unit_cost' => in_array($item->type, ['part', 'product'], true) ? number_format($unitCost, 2, '.', '') : null,
                'line_total' => $item->line_total,
                'revenue' => $lineRevenue,
                'refunded_quantity' => round($refundedQty, 2),
                'refunded_amount' => $lineRefundRevenue,
                'restocked_quantity' => round($restockQty, 2),
                'cogs' => $lineCogs,
                'profit' => $free
                    ? round(0 - $lineCogs, 2)
                    : ($isDiscount
                        ? round(-1 * (float) $item->line_total, 2)
                        : round($lineRevenue - $lineCogs, 2)),
            ];
        }

        return [
            'lines' => $lines,
            'discounts' => $discounts,
            'refunded_revenue' => $refundedRevenue,
            'cogs' => $cogs,
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
