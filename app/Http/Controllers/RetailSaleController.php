<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\BillItem;
use App\Models\BillPayment;
use App\Models\Customer;
use App\Models\Product;
use App\Models\RetailSale;
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
            'payment_method' => ['nullable', 'string', 'max:50'],
            'payment_amount' => ['nullable', 'numeric', 'min:0'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', Rule::exists('products', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
        ]);

        $sale = DB::transaction(function () use ($data, $request) {
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

            $sale = RetailSale::create([
                'customer_id' => $customer->id,
                'status' => 'completed',
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            $type = BusinessTypes::normalizeLegacy((string) ($request->user()->tenant?->business_type ?? BusinessTypes::CLOTHING));
            $bill = Bill::create([
                'bill_number' => BusinessTypes::billPrefix($type).'-'.now()->format('Ymd').'-'.strtoupper(str()->random(6)),
                'customer_id' => $customer->id,
                'admission_date' => today(),
                'notes' => $data['notes'] ?? null,
                'source_type' => RetailSale::class,
                'source_id' => $sale->id,
                'created_by' => $request->user()->id,
            ]);

            $subtotal = 0;
            foreach ($data['items'] as $line) {
                $product = Product::findOrFail($line['product_id']);
                $qty = (float) $line['quantity'];
                $needed = (int) ceil($qty);
                if (BranchInventory::productQty($product->id) < $needed) {
                    abort(response()->json(['message' => "Insufficient stock for {$product->name}."], 422));
                }
                $lineTotal = round($product->price * $qty, 2);
                BillItem::create([
                    'bill_id' => $bill->id,
                    'type' => 'product',
                    'description' => trim($product->name.' '.$product->size.' '.$product->color),
                    'quantity' => $qty,
                    'unit_price' => $product->price,
                    'line_total' => $lineTotal,
                ]);
                $product->takeStock($needed);
                $subtotal += $lineTotal;
            }

            $bill->update([
                'subtotal' => $subtotal,
                'balance_due' => $subtotal,
            ]);

            $payAmount = isset($data['payment_amount']) ? (float) $data['payment_amount'] : $subtotal;
            if ($payAmount > 0) {
                BillPayment::create([
                    'bill_id' => $bill->id,
                    'amount' => $payAmount,
                    'method' => $data['payment_method'] ?? 'cash',
                    'paid_at' => now(),
                    'received_by' => $request->user()->id,
                ]);
                $bill->update([
                    'amount_paid' => $payAmount,
                    'balance_due' => max(0, $subtotal - $payAmount),
                    'customer_balance' => max(0, $payAmount - $subtotal),
                    'status' => $payAmount >= $subtotal ? 'paid' : 'partially_paid',
                ]);
            }

            $sale->update(['bill_id' => $bill->id]);

            return $sale->load(['customer', 'bill.items', 'bill.payments']);
        });

        return response()->json($sale, 201);
    }

    public function show(RetailSale $sale): JsonResponse
    {
        return $this->moneyJson($sale->load(['customer', 'bill.items', 'bill.payments', 'creator']));
    }
}
