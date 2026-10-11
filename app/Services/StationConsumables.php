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
        return $this->matchingLines($issue, $this->candidateLines(collect([$issue])));
    }

    /**
     * @param  Collection<int, StockIssue>  $issues
     * @return array<int, array{washes: float, revenue: float, cost: float, cost_per_wash: float, profit: float, days: int}>
     */
    public function summariesFor(Collection $issues): array
    {
        $issues = $issues->unique('id')->values();
        $candidates = $this->candidateLines($issues);
        $summaries = [];
        foreach ($issues as $issue) {
            $summaries[$issue->id] = $this->summaryFromLines($issue, $this->matchingLines($issue, $candidates));
        }

        return $summaries;
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
        return $this->summaryFromLines($issue, $this->linesFor($issue));
    }

    /**
     * @param  Collection<int, BillItem>  $lines
     * @return array{washes: float, revenue: float, cost: float, cost_per_wash: float, profit: float, days: int}
     */
    private function summaryFromLines(StockIssue $issue, Collection $lines): array
    {
        $names = $issue->normalizedServiceNames();
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

        $candidates = $this->candidateLines($issues);
        $costs = [];
        foreach ($issues as $issue) {
            $lines = $this->matchingLines($issue, $candidates);
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

    /**
     * @param  Collection<int, StockIssue>  $issues
     * @return Collection<int, BillItem>
     */
    private function candidateLines(Collection $issues): Collection
    {
        if ($issues->isEmpty()) {
            return collect();
        }

        $from = $issues->min(fn (StockIssue $issue) => $issue->opened_at);
        $to = $issues->max(fn (StockIssue $issue) => $issue->closed_at ?? now());
        $branchIds = $issues->pluck('branch_id')->filter()->unique()->values();
        $needsEveryBranch = $issues->contains(fn (StockIssue $issue) => ! $issue->branch_id);

        return BillItem::query()
            ->where('type', 'service_addon')
            ->where('created_at', '>=', $from)
            ->when($to, fn ($query) => $query->where('created_at', '<=', $to))
            ->when(! $needsEveryBranch && $branchIds->isNotEmpty(), fn ($query) => $query->whereHas(
                'bill',
                fn ($bill) => $bill->withoutGlobalScope('branch')->whereIn('branch_id', $branchIds->all())
            ))
            ->with(['bill' => fn ($bill) => $bill->withoutGlobalScope('branch')->select('id', 'branch_id')])
            ->get(['id', 'bill_id', 'service_addon_id', 'description', 'included_services', 'quantity', 'line_total', 'created_at']);
    }

    /**
     * @param  Collection<int, BillItem>  $lines
     * @return Collection<int, BillItem>
     */
    private function matchingLines(StockIssue $issue, Collection $lines): Collection
    {
        $names = $issue->normalizedServiceNames();
        if ($names === [] || $lines->isEmpty()) {
            return collect();
        }

        return $lines
            ->filter(function (BillItem $line) use ($issue, $names) {
                if ($line->created_at === null || $line->created_at->lt($issue->opened_at)) {
                    return false;
                }
                if ($issue->closed_at && $line->created_at->gt($issue->closed_at)) {
                    return false;
                }
                if ($issue->branch_id && (int) $line->bill?->branch_id !== (int) $issue->branch_id) {
                    return false;
                }

                return $this->matches($line, $names) !== null;
            })
            ->values();
    }
}
