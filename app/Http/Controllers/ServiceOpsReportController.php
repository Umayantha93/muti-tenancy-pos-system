<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\BillItem;
use App\Models\ServiceAddon;
use App\Services\StationConsumables;
use App\Support\BranchQuery;
use App\Support\BusinessTypes;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServiceOpsReportController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        abort_unless(
            $request->user()->tenant?->business_type === BusinessTypes::GARAGE,
            403,
            'Service operations reports are only available for garage shops.',
        );

        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $from = Carbon::parse($data['from'] ?? now()->startOfMonth())->startOfDay();
        $to = Carbon::parse($data['to'] ?? now()->endOfMonth())->endOfDay();

        $billIds = BranchQuery::constrain(Bill::query())
            ->whereBetween('admission_date', [$from->toDateString(), $to->toDateString()])
            ->where('job_kind', '!=', Bill::JOB_KIND_PARTS_SALE)
            ->pluck('id');

        $lines = BillItem::query()
            ->whereIn('bill_id', $billIds)
            ->where('type', 'service_addon')
            ->get();

        $catalog = ServiceAddon::query()
            ->with('inclusions')
            ->get()
            ->keyBy(fn (ServiceAddon $addon) => self::key($addon->name));

        $consumableCosts = app(StationConsumables::class)->costByLine($from, $to);
        $sold = [];
        $inside = [];
        $labels = [];
        $fullKeys = [];
        $jobs = [];

        foreach ($lines as $line) {
            $key = self::key((string) $line->description);
            if ($key === '') {
                continue;
            }
            $qty = (float) $line->quantity;
            $jobs[$line->bill_id] = true;
            $labels[$key] ??= trim((string) $line->description);

            $sold[$key] ??= ['qty' => 0.0, 'revenue' => 0.0, 'cost' => 0.0];
            $sold[$key]['qty'] += $qty;
            $sold[$key]['revenue'] += (float) $line->line_total;
            $sold[$key]['cost'] += $consumableCosts[$line->id] ?? 0.0;

            $names = collect($line->included_services ?? [])->filter()->values();
            if ($names->isEmpty() && $catalog->get($key)?->is_full_service) {
                $names = $catalog->get($key)->inclusions->pluck('name');
            }
            if ($names->isEmpty()) {
                continue;
            }
            $fullKeys[$key] = true;
            foreach ($names as $name) {
                $includedKey = self::key((string) $name);
                if ($includedKey === '' || $includedKey === $key) {
                    continue;
                }
                $labels[$includedKey] ??= trim((string) $name);
                $inside[$includedKey] = ($inside[$includedKey] ?? 0) + $qty;
            }
        }

        $keys = collect(array_keys($sold))->merge(array_keys($inside))->unique()->values();
        $rows = $keys->map(function (string $key) use ($catalog, $sold, $inside, $labels, $fullKeys) {
            $addon = $catalog->get($key);
            $qty = round($sold[$key]['qty'] ?? 0, 2);
            $revenue = round($sold[$key]['revenue'] ?? 0, 2);
            $cost = round($sold[$key]['cost'] ?? 0, 2);
            $insideQty = round($inside[$key] ?? 0, 2);
            $isFull = isset($fullKeys[$key]) || (bool) $addon?->is_full_service;

            return [
                'key' => $key,
                'service_addon_id' => $addon?->id,
                'name' => $addon?->name ?? $labels[$key],
                'is_full_service' => $isFull,
                'sold_qty' => $qty,
                'inside_full_service' => $isFull ? null : $insideQty,
                'revenue' => $revenue,
                'consumable_cost' => $cost,
                'profit' => round($revenue - $cost, 2),
            ];
        })->sortBy([
            fn ($row) => $row['is_full_service'] ? 0 : 1,
            fn ($row) => mb_strtolower($row['name']),
        ])->values();

        $jobCount = count($jobs);
        $addonQty = round($rows->sum('sold_qty'), 2);
        $revenue = round($rows->sum('revenue'), 2);
        $consumableCost = round($rows->sum('consumable_cost'), 2);

        return $this->moneyJson([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'jobs' => $jobCount,
            'addon_revenue' => $revenue,
            'addon_consumable_cost' => $consumableCost,
            'addon_profit' => round($revenue - $consumableCost, 2),
            'average_addons_per_job' => $jobCount > 0 ? round($addonQty / $jobCount, 2) : 0,
            'rows' => $rows,
        ]);
    }

    private static function key(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $name)));
    }
}
