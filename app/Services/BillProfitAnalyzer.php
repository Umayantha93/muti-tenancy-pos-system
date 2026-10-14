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
     *   lines: list<array<string, mixed>>
     * }
     */
    public function summarize(Bill $bill): array
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

            if ($item->type === 'part') {
                $unitCost = (float) ($item->purchase_unit_cost ?? $item->part?->cost_price ?? 0);
            }

            $itemRefund = $refundMeta[$item->id] ?? [];
            $lineRefundRevenue = round((float) ($itemRefund['revenue'] ?? 0), 2);
            $restockQty = (float) ($itemRefund['restock_qty'] ?? 0);
            $refundedQty = (float) ($itemRefund['qty'] ?? 0);

            $lineCogs = round($unitCost * max(0.0, $quantity - $restockQty), 2);
            $lineRevenue = $isDiscount ? 0.0 : max(0.0, round((float) $item->line_total - $lineRefundRevenue, 2));
            if ($isDiscount) {
                $discounts += (float) $item->line_total;
            } else {
                $refundedRevenue += $lineRefundRevenue;
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
                'refunded_quantity' => round($refundedQty, 2),
                'refunded_amount' => $lineRefundRevenue,
                'restocked_quantity' => round($restockQty, 2),
                'cogs' => $lineCogs,
                'profit' => $isDiscount
                    ? round(-1 * (float) $item->line_total, 2)
                    : round($lineRevenue - $lineCogs, 2),
            ];
        }

        $revenue = max(0.0, round((float) $bill->subtotal - $discounts - $refundedRevenue, 2));
        $cogs = round($cogs, 2);
        $profit = round($revenue - $cogs, 2);

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
            'lines' => $lines,
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
            $revenue = (float) $item->line_total;
            $cogs = $item->type === 'part'
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
