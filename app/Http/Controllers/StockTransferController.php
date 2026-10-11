<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Part;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Models\StockTransferItem;
use App\Services\BranchInventory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StockTransferController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $transfers = StockTransfer::query()
            ->with([
                'fromBranch:id,name,code',
                'toBranch:id,name,code',
                'creator:id,name',
                'receiver:id,name',
                'items.part:id,name,sku,barcode',
                'items.product:id,name,sku',
            ])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->latest('id')
            ->paginate($request->integer('per_page', 30));

        return response()->json($transfers);
    }

    public function show(StockTransfer $stock_transfer): JsonResponse
    {
        return response()->json($this->payload($stock_transfer));
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'business_owner', 403);
        $tenantId = $request->user()->tenant_id;
        $data = $request->validate([
            'from_branch_id' => ['required', Rule::exists('branches', 'id')->where('tenant_id', $tenantId)],
            'to_branch_id' => ['required', Rule::exists('branches', 'id')->where('tenant_id', $tenantId), 'different:from_branch_id'],
            'notes' => ['nullable', 'string', 'max:500'],
            'part_id' => ['nullable', Rule::exists('parts', 'id')->where('tenant_id', $tenantId)],
            'product_id' => ['nullable', Rule::exists('products', 'id')->where('tenant_id', $tenantId)],
            'quantity' => ['nullable', 'integer', 'min:1'],
            'items' => ['nullable', 'array', 'min:1'],
            'items.*.part_id' => ['nullable', Rule::exists('parts', 'id')->where('tenant_id', $tenantId)],
            'items.*.product_id' => ['nullable', Rule::exists('products', 'id')->where('tenant_id', $tenantId)],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $lines = $this->normalizedLines($data);
        abort_unless(count($lines) > 0, 422, 'Add at least one stock line.');

        $from = Branch::query()->findOrFail($data['from_branch_id']);
        $to = Branch::query()->findOrFail($data['to_branch_id']);
        abort_unless($from->isActive() && $to->isActive(), 422, 'Both shops must be active.');

        $transfer = DB::transaction(function () use ($data, $lines, $request) {
            $transfer = StockTransfer::create([
                'from_branch_id' => $data['from_branch_id'],
                'to_branch_id' => $data['to_branch_id'],
                'quantity' => collect($lines)->sum('quantity'),
                'part_id' => count($lines) === 1 ? $lines[0]['part_id'] : null,
                'product_id' => count($lines) === 1 ? $lines[0]['product_id'] : null,
                'status' => StockTransfer::STATUS_PENDING,
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);
            $transfer->update([
                'transfer_number' => 'TRF-'.now()->format('Ymd').'-'.str_pad((string) $transfer->id, 4, '0', STR_PAD_LEFT),
            ]);

            foreach ($lines as $line) {
                StockTransferItem::create([
                    'stock_transfer_id' => $transfer->id,
                    'part_id' => $line['part_id'],
                    'product_id' => $line['product_id'],
                    'quantity' => $line['quantity'],
                ]);
            }

            $parts = $this->lockedParts(array_column($lines, 'part_id'));
            $products = $this->lockedProducts(array_column($lines, 'product_id'));
            foreach ($lines as $line) {
                if ($line['part_id']) {
                    $part = $parts->get($line['part_id']) ?? Part::query()->whereKey($line['part_id'])->lockForUpdate()->firstOrFail();
                    BranchInventory::dispatchPart($part, (int) $data['from_branch_id'], (int) $line['quantity']);
                } else {
                    $product = $products->get($line['product_id']) ?? Product::query()->whereKey($line['product_id'])->lockForUpdate()->firstOrFail();
                    BranchInventory::dispatchProduct($product, (int) $data['from_branch_id'], (int) $line['quantity']);
                }
            }

            return $transfer;
        });

        return response()->json($this->payload($transfer), 201);
    }

    public function receive(Request $request, StockTransfer $stock_transfer): JsonResponse
    {
        abort_unless($stock_transfer->isPending(), 422, 'This transfer is not waiting to be received.');
        $this->assertCanReceive($request, $stock_transfer);

        DB::transaction(function () use ($request, $stock_transfer) {
            $stock_transfer->load('items');
            $parts = $this->lockedParts($stock_transfer->items->pluck('part_id')->all());
            $products = $this->lockedProducts($stock_transfer->items->pluck('product_id')->all());
            foreach ($stock_transfer->items as $item) {
                if ($item->part_id) {
                    $part = $parts->get($item->part_id) ?? Part::query()->whereKey($item->part_id)->lockForUpdate()->firstOrFail();
                    BranchInventory::receivePart($part, (int) $stock_transfer->to_branch_id, (int) $item->quantity);
                } elseif ($item->product_id) {
                    $product = $products->get($item->product_id) ?? Product::query()->whereKey($item->product_id)->lockForUpdate()->firstOrFail();
                    BranchInventory::receiveProduct($product, (int) $stock_transfer->to_branch_id, (int) $item->quantity);
                }
            }
            $stock_transfer->update([
                'status' => StockTransfer::STATUS_RECEIVED,
                'received_at' => now(),
                'received_by' => $request->user()->id,
            ]);
        });

        return response()->json($this->payload($stock_transfer->fresh()));
    }

    public function destroy(Request $request, StockTransfer $stock_transfer): JsonResponse
    {
        abort_unless($request->user()->role === 'business_owner', 403);
        abort_unless($stock_transfer->isPending(), 422, 'Only a pending transfer can be cancelled.');

        DB::transaction(function () use ($stock_transfer) {
            $stock_transfer->load('items');
            $parts = $this->lockedParts($stock_transfer->items->pluck('part_id')->all());
            $products = $this->lockedProducts($stock_transfer->items->pluck('product_id')->all());
            foreach ($stock_transfer->items as $item) {
                if ($item->part_id) {
                    $part = $parts->get($item->part_id) ?? Part::query()->whereKey($item->part_id)->lockForUpdate()->firstOrFail();
                    BranchInventory::returnPart($part, (int) $item->quantity, (int) $stock_transfer->from_branch_id);
                } elseif ($item->product_id) {
                    $product = $products->get($item->product_id) ?? Product::query()->whereKey($item->product_id)->lockForUpdate()->firstOrFail();
                    BranchInventory::returnProduct($product, (int) $item->quantity, (int) $stock_transfer->from_branch_id);
                }
            }
            $stock_transfer->update(['status' => StockTransfer::STATUS_CANCELLED]);
        });

        return response()->json($this->payload($stock_transfer->fresh()));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array{part_id: int|null, product_id: int|null, quantity: int}>
     */
    private function normalizedLines(array $data): array
    {
        $raw = $data['items'] ?? [];
        if ($raw === [] && (! empty($data['part_id']) || ! empty($data['product_id']))) {
            $raw = [[
                'part_id' => $data['part_id'] ?? null,
                'product_id' => $data['product_id'] ?? null,
                'quantity' => $data['quantity'] ?? 0,
            ]];
        }

        $lines = [];
        foreach ($raw as $line) {
            $partId = $line['part_id'] ?? null;
            $productId = $line['product_id'] ?? null;
            if (! $partId && ! $productId) {
                throw ValidationException::withMessages(['items' => ['Each line must be a part or a product.']]);
            }
            if ($partId && $productId) {
                throw ValidationException::withMessages(['items' => ['A line cannot be both a part and a product.']]);
            }
            $lines[] = [
                'part_id' => $partId ? (int) $partId : null,
                'product_id' => $productId ? (int) $productId : null,
                'quantity' => (int) $line['quantity'],
            ];
        }

        return $lines;
    }

    /**
     * @param  list<int|null>  $ids
     * @return Collection<int, Part>
     */
    private function lockedParts(array $ids)
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return collect();
        }

        return Part::query()->whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');
    }

    /**
     * @param  list<int|null>  $ids
     * @return Collection<int, Product>
     */
    private function lockedProducts(array $ids)
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return collect();
        }

        return Product::query()->whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');
    }

    private function assertCanReceive(Request $request, StockTransfer $transfer): void
    {
        $user = $request->user();
        if ($user->role === 'business_owner') {
            return;
        }

        $active = (int) ($request->header('X-Branch-Id') ?: $user->home_branch_id);
        abort_unless($active === (int) $transfer->to_branch_id, 403, 'Receive this transfer at the destination shop.');
    }

    private function payload(StockTransfer $transfer): StockTransfer
    {
        return $transfer->load([
            'fromBranch:id,name,code,address,phone',
            'toBranch:id,name,code,address,phone',
            'creator:id,name',
            'receiver:id,name',
            'items.part:id,name,sku,barcode',
            'items.product:id,name,sku',
        ]);
    }
}
