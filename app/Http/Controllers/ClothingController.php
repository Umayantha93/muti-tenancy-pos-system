<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\Branch;
use App\Models\ClothingFloorState;
use App\Models\Product;
use App\Models\RetailSale;
use App\Services\BranchInventory;
use App\Support\BranchContext;
use App\Support\BusinessTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClothingController extends Controller
{
    public function insights(Request $request): JsonResponse
    {
        $this->assertClothing($request);

        $half = $request->boolean('half_view');
        $products = Product::query()->where('active', true)->get(['id', 'price', 'cost_price', 'stock_qty']);
        BranchInventory::overlayProducts($products);

        $worth = 0.0;
        $income = 0.0;
        $units = 0;
        foreach ($products as $product) {
            $qty = (int) $product->stock_qty;
            if ($half) {
                $qty = intdiv($qty, 2);
            }
            $units += $qty;
            $worth += $qty * (float) $product->price;
            $income += $qty * (float) $product->cost_price;
        }

        $bills = Bill::query()
            ->where('source_type', RetailSale::class)
            ->whereDate('admission_date', today())
            ->when($half, fn ($query) => $query->whereRaw($query->qualifyColumn('id').' % 2 = 0'))
            ->with('items:id,bill_id,type,quantity,line_total,purchase_unit_cost')
            ->get(['id', 'subtotal', 'total_deductions', 'vat_amount', 'sscl_amount']);

        $charged = 0.0;
        $taxableTotal = 0.0;
        $cost = 0.0;
        foreach ($bills as $bill) {
            $taxable = max(0, (float) $bill->subtotal - (float) $bill->total_deductions);
            $taxableTotal += $taxable;
            $charged += $taxable + (float) $bill->vat_amount + (float) $bill->sscl_amount;
            foreach ($bill->items as $item) {
                if ($item->type !== 'product' || $item->purchase_unit_cost === null) {
                    continue;
                }
                $cost += (float) $item->purchase_unit_cost * (float) $item->quantity;
            }
        }

        return response()->json([
            'worth' => round($worth, 2),
            'income' => round($income, 2),
            'profit' => round($worth - $income, 2),
            'units' => $units,
            'today_sales' => round($charged, 2),
            'today_profit' => round($taxableTotal - $cost, 2),
            'today_bills' => $bills->count(),
        ]);
    }

    public function showDisplay(Request $request): JsonResponse
    {
        $this->assertClothing($request);
        $state = $this->state($request);

        return response()->json([
            'business_name' => $request->user()->tenant?->business_name,
            'logo_url' => $request->user()->tenant?->logo_url,
            'payload' => $state?->payload ?: ['lines' => []],
            'updated_at' => $state?->updated_at,
        ]);
    }

    public function updateDisplay(Request $request): JsonResponse
    {
        $this->assertClothing($request);
        $payload = $request->validate([
            'lines' => ['present', 'array', 'max:40'],
            'lines.*.name' => ['required', 'string', 'max:255'],
            'lines.*.detail' => ['nullable', 'string', 'max:255'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.line_total' => ['required', 'numeric', 'min:0'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'subtotal' => ['nullable', 'numeric', 'min:0'],
            'tax' => ['nullable', 'numeric', 'min:0'],
            'total' => ['nullable', 'numeric', 'min:0'],
        ]);

        $state = ClothingFloorState::query()->updateOrCreate(
            ['branch_id' => $this->branchId($request)],
            ['payload' => $payload],
        );

        return response()->json([
            'business_name' => $request->user()->tenant?->business_name,
            'logo_url' => $request->user()->tenant?->logo_url,
            'payload' => $state->payload,
            'updated_at' => $state->updated_at,
        ]);
    }

    private function assertClothing(Request $request): void
    {
        $type = BusinessTypes::normalizeLegacy((string) ($request->user()->tenant?->business_type ?? ''));
        abort_unless($type === BusinessTypes::CLOTHING, 404);
    }

    private function state(Request $request): ?ClothingFloorState
    {
        return ClothingFloorState::query()
            ->where('branch_id', $this->branchId($request))
            ->first();
    }

    private function branchId(Request $request): int
    {
        return (int) (BranchContext::id() ?: Branch::defaultIdFor($request->user()->tenant_id) ?: 0);
    }
}
