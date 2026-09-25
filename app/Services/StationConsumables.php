<?php

namespace App\Services;

use App\Models\BillItem;
use App\Models\StockIssue;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class StationConsumables
{
    /**
     * Service addon lines billed while the tin was in use, in the tin's shop.
     *
     * @return Collection<int, BillItem>
     */
    public function linesFor(StockIssue $issue): Collection
    {
        $names = $issue->normalizedServiceNames();
        if ($names === []) {
            return collect();
        }

        return BillItem::query()
            ->where('type', 'service_addon')
            ->where('created_at', '>=', $issue->opened_at)
            ->when($issue->closed_at, fn ($query) => $query->where('created_at', '<=', $issue->closed_at))
            ->when($issue->branch_id, fn ($query) => $query->whereHas(
                'bill',
                fn ($bill) => $bill->withoutGlobalScope('branch')->where('branch_id', $issue->branch_id)
            ))
            ->get(['id', 'bill_id', 'service_addon_id', 'description', 'included_services', 'quantity', 'line_total', 'created_at'])
            ->filter(fn (BillItem $line) => $this->matches($line, $names) !== null)
            ->values();
    }

    /**
     * 'direct' when the line itself is one of the services, 'included' when it is a
     * full service that contains one of them, null otherwise.
     *
     * @param  list<string>  $names
     */
    public function matches(BillItem $line, array $names): ?string
    {
        if (in_array(mb_strtolower(trim((string) $line->description)), $names, true)) {
            return 'direct';
        }
        foreach ($line->included_services ?? [] as $included) {
            if (in_array(mb_strtolower(trim((string) $included)), $names, true)) {
                return 'included';
            }
        }

        return null;
    }

    /**
     * @return array{washes: float, revenue: float, cost: float, cost_per_wash: float, profit: float, days: int}
     */
    public function summary(StockIssue $issue): array
    {
        $names = $issue->normalizedServiceNames();
        $lines = $this->linesFor($issue);
        $washes = round((float) $lines->sum(fn (BillItem $line) => (float) $line->quantity), 2);
        $revenue = round((float) $lines
            ->filter(fn (BillItem $line) => $this->matches($line, $names) === 'direct')
            ->sum(fn (BillItem $line) => (float) $line->line_total), 2);
        $cost = $issue->totalCost();
        $end = $issue->closed_at ?? now();

        return [
            'washes' => $washes,
            'revenue' => $revenue,
            'cost' => $cost,
            'cost_per_wash' => $washes > 0 ? round($cost / $washes, 2) : 0.0,
            'profit' => round($revenue - $cost, 2),
            'days' => max(1, (int) ceil($issue->opened_at->diffInHours($end) / 24)),
        ];
    }

    /**
     * Consumable cost to charge against each service addon billed in the window,
     * keyed by bill item id. Each tin's cost is spread evenly over its washes.
     *
     * @return array<int, float>
     */
    public function costByLine(CarbonInterface $from, CarbonInterface $to): array
    {
        $issues = StockIssue::query()
            ->where('opened_at', '<=', $to)
            ->where(fn ($query) => $query->whereNull('closed_at')->orWhere('closed_at', '>=', $from))
            ->get();

        $costs = [];
        foreach ($issues as $issue) {
            $lines = $this->linesFor($issue);
            $washes = (float) $lines->sum(fn (BillItem $line) => (float) $line->quantity);
            if ($washes <= 0) {
                continue;
            }
            $perWash = $issue->totalCost() / $washes;
            foreach ($lines as $line) {
                $costs[$line->id] = ($costs[$line->id] ?? 0) + $perWash * (float) $line->quantity;
            }
        }

        return array_map(fn ($cost) => round($cost, 2), $costs);
    }
}
