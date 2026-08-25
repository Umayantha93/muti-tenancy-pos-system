<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Part;
use App\Models\Product;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Platform inventory corrections for a selected tenant.
 * Stock / catalogue edits here never create purchase expenses.
 */
class SuperAdminInventoryController extends Controller
{
    public function indexParts(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $parts = Part::query()
            ->where('tenant_id', $tenant->id)
            ->when(! empty($data['search']), function ($query) use ($data) {
                $like = '%'.$data['search'].'%';
                $query->where(fn ($nested) => $nested
                    ->where('name', 'like', $like)
                    ->orWhere('sku', 'like', $like)
                    ->orWhere('barcode', 'like', $like)
                    ->orWhere('brand', 'like', $like)
                    ->orWhere('type', 'like', $like)
                    ->orWhere('model', 'like', $like));
            })
            ->orderBy('name')
            ->paginate($data['per_page'] ?? 40);

        return $this->moneyJson($parts);
    }

    public function updatePart(Request $request, Tenant $tenant, Part $part): JsonResponse
    {
        $this->assertPartTenant($tenant, $part);

        $data = $request->validate([
            'stock_qty' => ['sometimes', 'integer', 'min:0'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'cost_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'name' => ['sometimes', 'string', 'max:255'],
            'sku' => ['sometimes', 'nullable', 'string', 'max:100'],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:100'],
            'brand' => ['sometimes', 'string', 'max:100'],
            'type' => ['sometimes', 'string', 'max:100'],
            'model' => ['sometimes', 'nullable', 'string', 'max:100'],
            'year' => ['sometimes', 'nullable', 'integer', 'between:1900,'.(now()->year + 1)],
            'description' => ['sometimes', 'nullable', 'string'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $note = $data['note'] ?? null;
        unset($data['note']);

        if ($data === []) {
            return $this->moneyJson($part->fresh());
        }

        $before = $part->only(['stock_qty', 'price', 'cost_price', 'name', 'sku', 'brand']);

        DB::transaction(function () use ($part, $data) {
            $part->update($data);
        });

        $this->audit($request, $tenant, Part::class, $part->id, 'inventory.part_corrected', [
            'before' => $before,
            'after' => $part->fresh()->only(['stock_qty', 'price', 'cost_price', 'name', 'sku', 'brand']),
            'note' => $note,
            'expense_created' => false,
        ]);

        return $this->moneyJson($part->fresh());
    }

    public function indexProducts(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $products = Product::query()
            ->where('tenant_id', $tenant->id)
            ->when(! empty($data['search']), function ($query) use ($data) {
                $like = '%'.$data['search'].'%';
                $query->where(fn ($nested) => $nested
                    ->where('name', 'like', $like)
                    ->orWhere('sku', 'like', $like)
                    ->orWhere('category', 'like', $like));
            })
            ->orderBy('name')
            ->paginate($data['per_page'] ?? 40);

        return $this->moneyJson($products);
    }

    public function updateProduct(Request $request, Tenant $tenant, Product $product): JsonResponse
    {
        $this->assertProductTenant($tenant, $product);

        $data = $request->validate([
            'stock_qty' => ['sometimes', 'integer', 'min:0'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'cost_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'name' => ['sometimes', 'string', 'max:255'],
            'sku' => ['sometimes', 'nullable', 'string', 'max:100'],
            'category' => ['sometimes', 'nullable', 'string', 'max:100'],
            'size' => ['sometimes', 'nullable', 'string', 'max:50'],
            'color' => ['sometimes', 'nullable', 'string', 'max:50'],
            'description' => ['sometimes', 'nullable', 'string'],
            'active' => ['sometimes', 'boolean'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $note = $data['note'] ?? null;
        unset($data['note']);

        if ($data === []) {
            return $this->moneyJson($product->fresh());
        }

        $before = $product->only(['stock_qty', 'price', 'cost_price', 'name', 'sku']);

        DB::transaction(function () use ($product, $data) {
            $product->update($data);
        });

        $this->audit($request, $tenant, Product::class, $product->id, 'inventory.product_corrected', [
            'before' => $before,
            'after' => $product->fresh()->only(['stock_qty', 'price', 'cost_price', 'name', 'sku']),
            'note' => $note,
            'expense_created' => false,
        ]);

        return $this->moneyJson($product->fresh());
    }

    private function assertPartTenant(Tenant $tenant, Part $part): void
    {
        abort_unless($part->tenant_id === $tenant->id, 404);
    }

    private function assertProductTenant(Tenant $tenant, Product $product): void
    {
        abort_unless($product->tenant_id === $tenant->id, 404);
    }

    private function audit(Request $request, Tenant $tenant, string $subjectType, int $subjectId, string $action, array $metadata = []): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id,
            'tenant_id' => $tenant->id,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'metadata' => array_filter($metadata, fn ($value) => $value !== null && $value !== ''),
            'ip_address' => $request->ip(),
        ]);
    }
}
