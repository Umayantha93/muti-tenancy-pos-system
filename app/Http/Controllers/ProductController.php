<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Product;
use App\Models\StockReceipt;
use App\Models\StockReceiptItem;
use App\Services\BranchInventory;
use App\Support\InventoryCosting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $products = Product::query()
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = '%'.$request->string('search').'%';
                $q->where(fn ($n) => $n->where('name', 'like', $search)->orWhere('sku', 'like', $search)->orWhere('category', 'like', $search));
            })
            ->when($request->boolean('active_only'), fn ($q) => $q->where('active', true))
            ->latest()
            ->paginate($request->integer('per_page', 30));

        $products->getCollection()->transform(fn (Product $product) => BranchInventory::overlayProduct($product));

        return $this->moneyJson($products);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:100'],
            'size' => ['nullable', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'max:50'],
            'price' => ['required', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'stock_qty' => ['nullable', 'integer', 'min:0'],
            'description' => ['nullable', 'string'],
            'active' => ['sometimes', 'boolean'],
        ]);

        return response()->json(Product::create($data), 201);
    }

    public function show(Product $product): JsonResponse
    {
        return $this->moneyJson(BranchInventory::overlayProduct($product));
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:100'],
            'size' => ['nullable', 'string', 'max:50'],
            'color' => ['nullable', 'string', 'max:50'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'cost_price' => ['nullable', 'numeric', 'min:0'],
            'stock_qty' => ['nullable', 'integer', 'min:0'],
            'description' => ['nullable', 'string'],
            'active' => ['sometimes', 'boolean'],
        ]);
        $product->update($data);

        return response()->json($product->refresh());
    }

    public function destroy(Product $product): JsonResponse
    {
        $product->delete();

        return response()->json(null, 204);
    }

    public function restock(Request $request, Product $product): JsonResponse
    {
        if ($request->input('due_date') === '') {
            $request->merge(['due_date' => null]);
        }
        if ($request->input('supplier_id') === '' || $request->input('supplier_id') === '0') {
            $request->merge(['supplier_id' => null]);
        }

        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
            'unit_cost' => ['nullable', 'numeric', 'min:0'],
            'expense_date' => ['nullable', 'date'],
            'payment_status' => ['nullable', Rule::in(['paid', 'credit'])],
            'due_date' => ['nullable', 'date', 'after_or_equal:today', 'required_if:payment_status,credit'],
            'supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->where('tenant_id', $request->user()->tenant_id)],
        ]);

        [$product, $expense] = DB::transaction(function () use ($data, $product, $request) {
            $qty = (int) $data['quantity'];
            $unitCostProvided = array_key_exists('unit_cost', $data) && $data['unit_cost'] !== null;
            $unitCost = $unitCostProvided
                ? (float) $data['unit_cost']
                : (float) ($product->cost_price ?? 0);
            $shopQty = BranchInventory::productQty($product->id);
            $blendedCost = InventoryCosting::weightedAverageCost(
                $shopQty,
                $product->cost_price,
                $qty,
                $unitCost,
            );
            BranchInventory::returnProduct($product, $qty);
            $product->refresh();
            $product->update([
                'cost_price' => $blendedCost,
            ]);

            $expense = null;
            // Only book an expense when a purchase unit cost was entered (same rule as before).
            if ($unitCostProvided && $unitCost > 0) {
                $paid = ($data['payment_status'] ?? 'paid') !== Expense::STATUS_CREDIT;
                $date = $data['expense_date'] ?? now()->toDateString();
                $expense = Expense::create([
                    'category' => 'inventory',
                    'description' => $paid
                        ? "Stock purchase: {$product->name} × {$qty}"
                        : "Stock purchase on credit: {$product->name} × {$qty}",
                    'amount' => round($unitCost * $qty, 2),
                    'expense_date' => $date,
                    'payment_status' => $paid ? Expense::STATUS_PAID : Expense::STATUS_CREDIT,
                    'due_date' => $paid ? null : ($data['due_date'] ?? null),
                    'settled_at' => $paid ? $date : null,
                    'created_by' => $request->user()->id,
                    'supplier_id' => $data['supplier_id'] ?? null,
                ]);

                if (! empty($data['supplier_id'])) {
                    $count = StockReceipt::query()->count() + 1;
                    $receipt = StockReceipt::create([
                        'supplier_id' => $data['supplier_id'],
                        'expense_id' => $expense->id,
                        'receipt_number' => 'GRN-'.str_pad((string) $count, 4, '0', STR_PAD_LEFT),
                        'received_at' => $date,
                        'payment_status' => $paid ? 'paid' : 'credit',
                        'due_date' => $paid ? null : ($data['due_date'] ?? null),
                    ]);
                    StockReceiptItem::create([
                        'stock_receipt_id' => $receipt->id,
                        'item_type' => 'product',
                        'product_id' => $product->id,
                        'quantity' => $qty,
                        'unit_cost' => $unitCost,
                    ]);
                    $expense->update(['stock_receipt_id' => $receipt->id]);
                }
            }

            return [$product->refresh(), $expense];
        });

        return response()->json($product);
    }
}
