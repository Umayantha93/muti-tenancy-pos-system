<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Part;
use App\Models\ServiceAddon;
use App\Models\StockIssue;
use App\Services\StationConsumables;
use App\Support\BranchContext;
use App\Support\BranchQuery;
use App\Support\StockUnit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StationConsumableController extends Controller
{
    public function __construct(private readonly StationConsumables $consumables) {}

    public function index(Request $request): JsonResponse
    {
        $relations = ['part:id,name,sku,stock_unit', 'branch:id,name', 'creator:id,name', 'closer:id,name'];

        $open = BranchQuery::constrain(StockIssue::query())
            ->with($relations)
            ->whereNull('closed_at')
            ->latest('opened_at')
            ->get();

        $history = BranchQuery::constrain(StockIssue::query())
            ->with($relations)
            ->whereNotNull('closed_at')
            ->latest('closed_at')
            ->paginate(min(max($request->integer('per_page', 20), 1), 100));

        $averages = $this->averageWashesPerUnit($open->pluck('part_id')->filter()->unique()->all());

        $history->getCollection()->transform(fn (StockIssue $issue) => $this->present($issue));

        return $this->moneyJson([
            'open' => $open->map(fn (StockIssue $issue) => [
                ...$this->present($issue),
                'average_washes_per_unit' => $averages[$issue->part_id] ?? null,
            ])->values(),
            'history' => $history,
            'services' => ServiceAddon::query()
                ->where('active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->pluck('name')
                ->map(fn ($name) => trim((string) $name))
                ->unique(fn ($name) => mb_strtolower($name))
                ->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $data = $request->validate([
            'part_id' => ['required', 'integer', Rule::exists('parts', 'id')->where('tenant_id', $tenantId)],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'service_names' => ['required', 'array', 'min:1'],
            'service_names.*' => ['required', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:500'],
            'close_previous' => ['sometimes', 'boolean'],
        ]);

        $part = Part::query()->findOrFail($data['part_id']);
        $businessType = $request->user()->tenant?->business_type;
        $unit = StockUnit::normalize($part->stock_unit, $businessType);
        if (! StockUnit::allowsDecimal($unit, $businessType) && ! StockUnit::isWhole((float) $data['quantity'])) {
            throw ValidationException::withMessages(['quantity' => ['This item is counted in whole units.']]);
        }

        $branchId = BranchContext::id() ?? Branch::defaultIdFor($tenantId);
        $previous = StockIssue::query()
            ->where('part_id', $part->id)
            ->when($branchId, fn ($query) => $query->where('branch_id', $branchId))
            ->whereNull('closed_at')
            ->latest('opened_at')
            ->first();

        if ($previous && ! ($data['close_previous'] ?? false)) {
            return response()->json([
                'message' => "A {$part->name} tin opened on {$previous->opened_at->toDateString()} is still in use.",
                'open_issue' => ['id' => $previous->id, 'opened_at' => $previous->opened_at->toDateTimeString()],
            ], 409);
        }

        $issue = DB::transaction(function () use ($request, $data, $part, $branchId, $previous) {
            if ($previous) {
                $previous->update(['closed_at' => now(), 'closed_by' => $request->user()->id]);
            }

            $part->takeStock((float) $data['quantity'], $branchId);

            return StockIssue::create([
                'branch_id' => $branchId,
                'part_id' => $part->id,
                'quantity' => (float) $data['quantity'],
                'unit_cost' => (float) ($part->cost_price ?? 0),
                'service_names' => collect($data['service_names'])->map(fn ($name) => trim($name))->filter()->unique()->values()->all(),
                'opened_at' => now(),
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);
        });

        return $this->moneyJson($this->present($issue->load('part:id,name,sku,stock_unit')), 201);
    }

    public function close(Request $request, StockIssue $stockIssue): JsonResponse
    {
        abort_unless($stockIssue->isOpen(), 422, 'This tin is already marked finished.');

        $data = $request->validate([
            'returned_qty' => ['nullable', 'numeric', 'min:0', 'max:'.$stockIssue->quantity],
        ]);
        $returned = (float) ($data['returned_qty'] ?? 0);

        DB::transaction(function () use ($request, $stockIssue, $returned) {
            if ($returned > 0 && $stockIssue->part) {
                $stockIssue->part->returnStock($returned, $stockIssue->branch_id);
            }
            $stockIssue->update([
                'returned_qty' => $returned,
                'closed_at' => now(),
                'closed_by' => $request->user()->id,
            ]);
        });

        return $this->moneyJson($this->present($stockIssue->refresh()->load('part:id,name,sku,stock_unit')));
    }

    public function destroy(StockIssue $stockIssue): JsonResponse
    {
        abort_unless($stockIssue->isOpen(), 422, 'Finished tins cannot be voided.');
        abort_unless($stockIssue->opened_at->isToday(), 422, 'Only a tin issued today can be voided.');

        DB::transaction(function () use ($stockIssue) {
            $stockIssue->part?->returnStock((float) $stockIssue->quantity, $stockIssue->branch_id);
            $stockIssue->delete();
        });

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(StockIssue $issue): array
    {
        return [
            'id' => $issue->id,
            'part' => $issue->part ? [
                'id' => $issue->part->id,
                'name' => $issue->part->name,
                'sku' => $issue->part->sku,
                'stock_unit' => $issue->part->stock_unit,
            ] : null,
            'branch' => $issue->branch ? ['id' => $issue->branch->id, 'name' => $issue->branch->name] : null,
            'quantity' => (float) $issue->quantity,
            'returned_qty' => (float) $issue->returned_qty,
            'unit_cost' => $issue->unit_cost,
            'service_names' => $issue->service_names ?? [],
            'opened_at' => $issue->opened_at?->toDateTimeString(),
            'closed_at' => $issue->closed_at?->toDateTimeString(),
            'notes' => $issue->notes,
            'opened_by' => $issue->creator?->name,
            'closed_by' => $issue->closer?->name,
            'can_void' => $issue->isOpen() && $issue->opened_at?->isToday(),
            ...$this->consumables->summary($issue),
        ];
    }

    /**
     * @param  list<int>  $partIds
     * @return array<int, float>
     */
    private function averageWashesPerUnit(array $partIds): array
    {
        $averages = [];
        foreach ($partIds as $partId) {
            $recent = StockIssue::query()
                ->where('part_id', $partId)
                ->whereNotNull('closed_at')
                ->latest('closed_at')
                ->limit(5)
                ->get();
            $perUnit = $recent
                ->filter(fn (StockIssue $issue) => $issue->usedQty() > 0)
                ->map(fn (StockIssue $issue) => $this->consumables->summary($issue)['washes'] / $issue->usedQty())
                ->filter(fn ($value) => $value > 0);
            if ($perUnit->isNotEmpty()) {
                $averages[$partId] = round($perUnit->avg(), 2);
            }
        }

        return $averages;
    }
}
