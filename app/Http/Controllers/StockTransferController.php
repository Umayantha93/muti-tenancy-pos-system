<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Part;
use App\Models\Product;
use App\Models\StockTransfer;
use App\Services\BranchInventory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class StockTransferController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'business_owner', 403);
        $tenantId = $request->user()->tenant_id;
        $data = $request->validate([
            'from_branch_id' => ['required', Rule::exists('branches', 'id')->where('tenant_id', $tenantId)],
            'to_branch_id' => ['required', Rule::exists('branches', 'id')->where('tenant_id', $tenantId), 'different:from_branch_id'],
            'part_id' => ['nullable', Rule::exists('parts', 'id')->where('tenant_id', $tenantId)],
            'product_id' => ['nullable', Rule::exists('products', 'id')->where('tenant_id', $tenantId)],
            'quantity' => ['required', 'integer', 'min:1'],
        ]);
        abort_unless(! empty($data['part_id']) xor ! empty($data['product_id']), 422, 'Choose either a part or a product.');

        $from = Branch::query()->findOrFail($data['from_branch_id']);
        $to = Branch::query()->findOrFail($data['to_branch_id']);
        abort_unless($from->isActive() && $to->isActive(), 422, 'Both shops must be active.');

        $transfer = DB::transaction(function () use ($data, $request) {
            if (! empty($data['part_id'])) {
                $part = Part::query()->lockForUpdate()->findOrFail($data['part_id']);
                BranchInventory::transferPart($part, (int) $data['from_branch_id'], (int) $data['to_branch_id'], (int) $data['quantity']);
            } else {
                $product = Product::query()->lockForUpdate()->findOrFail($data['product_id']);
                BranchInventory::transferProduct($product, (int) $data['from_branch_id'], (int) $data['to_branch_id'], (int) $data['quantity']);
            }

            return StockTransfer::create([
                'from_branch_id' => $data['from_branch_id'],
                'to_branch_id' => $data['to_branch_id'],
                'part_id' => $data['part_id'] ?? null,
                'product_id' => $data['product_id'] ?? null,
                'quantity' => $data['quantity'],
                'created_by' => $request->user()->id,
            ]);
        });

        return response()->json($transfer->load(['fromBranch', 'toBranch', 'part', 'product']), 201);
    }
}
