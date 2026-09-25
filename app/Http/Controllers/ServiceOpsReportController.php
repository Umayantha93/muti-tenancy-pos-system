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
            ->pluck('id');

        $lines = BillItem::query()
            ->with(['serviceAddon' => fn ($query) => $query->withoutGlobalScopes()->with('vehicleClass:id,name')])
            ->whereIn('bill_id', $billIds)
            ->where('type', 'service_addon')
            ->whereNotNull('service_addon_id')
            ->get();

        $catalog = ServiceAddon::query()
            ->with(['inclusions', 'vehicleClass:id,name'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
        $byId = $catalog->keyBy('id');
        $byName = $catalog->mapWithKeys(function (ServiceAddon $addon) {
            $classKey = $addon->service_vehicle_class_id ? ':'.$addon->service_vehicle_class_id : '';

            return [mb_strtolower($addon->name).$classKey => $addon->id];
        });

        $consumableCosts = app(StationConsumables::class)->costByLine($from, $to);
        $sold = [];
        $inside = [];
        $jobs = [];

        foreach ($lines as $line) {
            $addonId = (int) $line->service_addon_id;
            $qty = (float) $line->quantity;
            $total = (float) $line->line_total;
            $jobs[$line->bill_id] = true;

            $sold[$addonId] = $sold[$addonId] ?? ['qty' => 0.0, 'revenue' => 0.0, 'cost' => 0.0];
            $sold[$addonId]['qty'] += $qty;
            $sold[$addonId]['revenue'] += $total;
            $sold[$addonId]['cost'] += $consumableCosts[$line->id] ?? 0.0;

            $addon = $line->serviceAddon ?? $byId->get($addonId);
            $isFull = (bool) ($addon?->is_full_service);
            $names = collect($line->included_services ?? [])->filter()->values();
            if ($names->isEmpty() && $isFull) {
                $names = collect($byId->get($addonId)?->inclusions?->pluck('name') ?? []);
            }
            if ($names->isEmpty()) {
                continue;
            }
            $classKey = $addon?->service_vehicle_class_id ? ':'.$addon->service_vehicle_class_id : '';
            foreach ($names as $name) {
                $includedId = $byName[mb_strtolower(trim((string) $name)).$classKey] ?? null;
                if (! $includedId || $includedId === $addonId) {
                    continue;
                }
                $inside[$includedId] = ($inside[$includedId] ?? 0) + $qty;
            }
        }

        $ids = collect(array_keys($sold))->merge(array_keys($inside))->unique()->values();
        $rows = $ids->map(function ($id) use ($byId, $sold, $inside, $lines) {
            $addon = $byId->get($id) ?? $lines->firstWhere('service_addon_id', $id)?->serviceAddon;
            $qty = round($sold[$id]['qty'] ?? 0, 2);
            $revenue = round($sold[$id]['revenue'] ?? 0, 2);
            $cost = round($sold[$id]['cost'] ?? 0, 2);
            $insideQty = round($inside[$id] ?? 0, 2);
            $isFull = (bool) ($addon?->is_full_service);
            $className = $addon?->vehicleClass?->name;
            $label = $addon?->name ?? 'Service';
            if ($className) {
                $label .= ' · '.$className;
            }

            return [
                'service_addon_id' => $id,
                'name' => $label,
                'vehicle_class' => $className,
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
}
