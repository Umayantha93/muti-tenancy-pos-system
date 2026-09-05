<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Part;
use App\Models\Product;
use App\Models\Tenant;
use App\Services\BranchInventory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SuperAdminBranchController extends Controller
{
    public function index(Tenant $tenant): JsonResponse
    {
        return response()->json(
            Branch::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get()
        );
    }

    public function store(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'regex:/^[0-9+() -]{7,20}$/'],
        ]);

        $code = strtoupper(trim((string) ($data['code'] ?? ''))) ?: Branch::uniqueCodeFor($tenant->id, $data['name']);
        abort_if(
            Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('code', $code)->exists(),
            422,
            'A shop with that code already exists.',
        );
        abort_if(
            Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('name', $data['name'])->exists(),
            422,
            'A shop with that name already exists.',
        );

        $branch = Branch::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => $data['name'],
            'code' => $code,
            'address' => $data['address'] ?? null,
            'phone' => $data['phone'] ?? null,
            'is_default' => false,
            'status' => 'active',
        ]);

        $this->seedEmptyStock($tenant, $branch);

        return response()->json($branch, 201);
    }

    public function update(Request $request, Tenant $tenant, Branch $branch): JsonResponse
    {
        abort_unless($branch->tenant_id === $tenant->id, 404);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'regex:/^[0-9+() -]{7,20}$/'],
        ]);
        if (! empty($data['code'])) {
            $data['code'] = strtoupper($data['code']);
        }
        $branch->update($data);

        return response()->json($branch->refresh());
    }

    public function deactivate(Tenant $tenant, Branch $branch): JsonResponse
    {
        abort_unless($branch->tenant_id === $tenant->id, 404);
        abort_if($branch->is_default, 422, 'The default shop cannot be deactivated.');
        $branch->update(['status' => 'inactive']);

        return response()->json($branch->refresh());
    }

    public function activate(Tenant $tenant, Branch $branch): JsonResponse
    {
        abort_unless($branch->tenant_id === $tenant->id, 404);
        $branch->update(['status' => 'active']);

        return response()->json($branch->refresh());
    }

    private function seedEmptyStock(Tenant $tenant, Branch $branch): void
    {
        BranchInventory::$mutating = true;
        try {
            Part::withoutGlobalScopes()->where('tenant_id', $tenant->id)->each(function (Part $part) use ($tenant, $branch) {
                $part->branchStocks()->firstOrCreate(
                    ['branch_id' => $branch->id, 'part_id' => $part->id],
                    ['tenant_id' => $tenant->id, 'qty' => 0],
                );
            });
            Product::withoutGlobalScopes()->where('tenant_id', $tenant->id)->each(function (Product $product) use ($tenant, $branch) {
                $product->branchStocks()->firstOrCreate(
                    ['branch_id' => $branch->id, 'product_id' => $product->id],
                    ['tenant_id' => $tenant->id, 'qty' => 0],
                );
            });
        } finally {
            BranchInventory::$mutating = false;
        }
    }
}
