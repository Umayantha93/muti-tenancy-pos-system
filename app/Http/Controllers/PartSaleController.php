<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\BillItem;
use App\Models\BillPayment;
use App\Models\Customer;
use App\Models\Part;
use App\Models\PartSale;
use App\Services\BillCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PartSaleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $sales = PartSale::query()
            ->with(['customer', 'bill.items', 'bill.payments'])
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return $this->moneyJson($sales);
    }

    public function store(Request $request, BillCalculator $calculator): JsonResponse
    {
        $data = $request->validate([
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'regex:/^[0-9+() -]{7,20}$/'],
            'notes' => ['nullable', 'string'],
            'payment_method' => ['nullable', 'string', 'max:50'],
            'payment_amount' => ['nullable', 'numeric', 'min:0'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.part_id' => ['required', Rule::exists('parts', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $sale = DB::transaction(function () use ($data, $request, $calculator) {
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

            $sale = PartSale::create([
                'customer_id' => $customer->id,
                'status' => 'completed',
                'notes' => $data['notes'] ?? null,
                'created_by' => $request->user()->id,
            ]);

            $bill = Bill::create([
                'bill_number' => 'PART-'.now()->format('Ymd').'-'.strtoupper(str()->random(6)),
                'customer_id' => $customer->id,
                'admission_date' => today(),
                'notes' => $data['notes'] ?? null,
                'job_kind' => Bill::JOB_KIND_PARTS_SALE,
                'source_type' => PartSale::class,
                'source_id' => $sale->id,
                'created_by' => $request->user()->id,
            ]);

            foreach ($data['items'] as $line) {
                $part = Part::findOrFail($line['part_id']);
                $qty = (int) $line['quantity'];
                $part->takeStock($qty);
                $unitPrice = (float) $part->price;
                $lineTotal = round($unitPrice * $qty, 2);
                BillItem::create([
                    'bill_id' => $bill->id,
                    'type' => 'part',
                    'part_id' => $part->id,
                    'description' => $part->name,
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal,
                    'purchase_unit_cost' => $part->cost_price,
                ]);
            }

            $calculator->recalculate($bill);

            $payAmount = isset($data['payment_amount'])
                ? (float) $data['payment_amount']
                : (float) $bill->fresh()->balance_due;

            if ($payAmount > 0) {
                BillPayment::create([
                    'bill_id' => $bill->id,
                    'amount' => $payAmount,
                    'method' => $data['payment_method'] ?? 'cash',
                    'paid_at' => now(),
                    'received_by' => $request->user()->id,
                ]);
                $calculator->recalculate($bill->fresh());
            }

            $sale->update(['bill_id' => $bill->id]);

            return $sale->load(['customer', 'bill.items.part', 'bill.payments']);
        });

        return $this->moneyJson($sale, 201);
    }

    public function show(PartSale $sale): JsonResponse
    {
        return $this->moneyJson($sale->load(['customer', 'bill.items.part', 'bill.payments', 'creator']));
    }
}
