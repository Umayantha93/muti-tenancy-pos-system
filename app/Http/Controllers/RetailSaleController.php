<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\BillItem;
use App\Models\BillPayment;
use App\Models\Customer;
use App\Models\Product;
use App\Models\RetailSale;
use App\Services\BillCalculator;
use App\Services\BranchInventory;
use App\Support\BranchQuery;
use App\Support\BusinessTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class RetailSaleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $sales = RetailSale::query()
            ->with(['customer', 'bill.items', 'bill.payments'])
            ->tap(fn ($query) => BranchQuery::constrain($query))
            ->latest()
            ->paginate($request->integer('per_page', 15));

        return $this->moneyJson($sales);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'regex:/^[0-9+() -]{7,20}$/'],
            'notes' => ['nullable', 'string'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'vip' => ['sometimes', 'boolean'],
            'payment_method' => ['nullable', Rule::in(['cash', 'card', 'qr', 'credit', 'bank_transfer'])],
            'payment_amount' => ['nullable', 'numeric', 'min:0'],
            'cash_tendered' => ['nullable', 'numeric', 'min:0'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', Rule::exists('products', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
        ]);

        $change = 0.0;
        $sale = DB::transaction(function () use ($data, $request, &$change) {
            if (! empty($data['customer_phone'])) {
                $customer = Customer::firstOrCreate(
                    ['phone' => $data['customer_phone']],
                    ['name' => $data['customer_name'] ?? 'Walk-in customer'],
                );
                if (! empty($data['customer_name'])) {
                    $customer->update(['name' => $data['customer_name']]);
                }
            } else {
                $customer = Customer::firstOrCreate(
                    ['phone' => '0000000000'],
                    ['name' => 'Walk-in customer'],
                );
            }

            if (array_key_exists('vip', $data)) {
                $customer->update(['is_vip' => (bool) $data['vip']]);
            }

            $sale = RetailSale::create([
                'customer_id' => $customer->id,
                'status' => 'completed',
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            $type = BusinessTypes::normalizeLegacy((string) ($request->user()->tenant?->business_type ?? BusinessTypes::CLOTHING));
            $billNumber = $request->user()->tenant?->claimNextBillNumber()
                ?? BusinessTypes::billPrefix($type).'-'.now()->format('Ymd').'-'.strtoupper(str()->random(6));
            $bill = Bill::create([
                'bill_number' => $billNumber,
                'customer_id' => $customer->id,
                'admission_date' => today(),
                'notes' => $data['notes'] ?? null,
                'vat_rate' => 0,
                'source_type' => RetailSale::class,
                'source_id' => $sale->id,
                'created_by' => $request->user()->id,
            ]);

            $productIds = collect($data['items'])->pluck('product_id')->filter()->map(fn ($id) => (int) $id)->unique()->values();
            $products = $productIds->isEmpty()
                ? collect()
                : Product::query()->whereIn('id', $productIds->all())->lockForUpdate()->get()->keyBy('id');

            $subtotal = 0;
            foreach ($data['items'] as $line) {
                $product = $products->get((int) $line['product_id']) ?? Product::query()->whereKey($line['product_id'])->lockForUpdate()->firstOrFail();
                $qty = (int) round((float) $line['quantity']);
                if ($qty < 1) {
                    abort(response()->json(['message' => 'Sell whole pieces only.'], 422));
                }
                if (BranchInventory::productQty($product->id) < $qty) {
                    abort(response()->json(['message' => "Insufficient stock for {$product->name}."], 422));
                }
                $lineTotal = round($product->price * $qty, 2);
                BillItem::create([
                    'bill_id' => $bill->id,
                    'type' => 'product',
                    'description' => trim($product->name.' '.$product->size.' '.$product->color),
                    'quantity' => $qty,
                    'unit_price' => $product->price,
                    'purchase_unit_cost' => $product->cost_price,
                    'line_total' => $lineTotal,
                ]);
                $product->takeStock($qty);
                $subtotal += $lineTotal;
            }

            $discount = round(min($subtotal, max(0, (float) ($data['discount'] ?? 0))), 2);
            if ($discount > 0) {
                BillItem::create([
                    'bill_id' => $bill->id,
                    'type' => 'discount',
                    'description' => 'Discount',
                    'quantity' => 1,
                    'unit_price' => $discount,
                    'line_total' => $discount,
                ]);
            }

            $calculator = app(BillCalculator::class);
            $calculator->recalculate($bill);
            $method = $data['payment_method'] ?? 'cash';
            $due = round((float) $bill->balance_due, 2);
            if ($method === 'cash' && isset($data['cash_tendered'])) {
                $tendered = round((float) $data['cash_tendered'], 2);
                if ($tendered + 0.009 < $due) {
                    abort(response()->json(['message' => 'Cash received is less than the amount due.'], 422));
                }
                $change = round($tendered - $due, 2);
            }
            $payAmount = $method === 'credit'
                ? 0.0
                : (isset($data['payment_amount']) ? min((float) $data['payment_amount'], $due) : $due);
            if ($payAmount > 0) {
                BillPayment::create([
                    'bill_id' => $bill->id,
                    'amount' => $payAmount,
                    'method' => $method,
                    'paid_at' => now(),
                    'received_by' => $request->user()->id,
                ]);
                $calculator->recalculate($bill);
            }

            $sale->update(['bill_id' => $bill->id]);

            return $sale->load(['customer', 'bill.items', 'bill.payments']);
        });
        $sale->setAttribute('change', $change);

        return response()->json($sale, 201);
    }

    public function show(RetailSale $sale): JsonResponse
    {
        return $this->moneyJson($sale->load(['customer', 'bill.items', 'bill.payments', 'creator']));
    }
}
