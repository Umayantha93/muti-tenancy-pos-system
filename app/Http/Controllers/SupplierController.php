<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\StockReceipt;
use App\Models\Supplier;
use App\Support\BusinessTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(Supplier::query()
            ->when($request->filled('search'), fn ($query) => $query->where('name', 'like', '%'.$request->string('search').'%'))
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->paginate($request->integer('per_page', 50)));
    }

    public function show(Supplier $supplier): JsonResponse
    {
        $receipts = $supplier->receipts()
            ->with(['items.part:id,name,type', 'expense:id,amount,amount_paid,payment_status,due_date,settled_at'])
            ->latest('received_at')
            ->latest('id')
            ->get();

        $creditExpenses = $supplier->expenses()
            ->credit()
            ->with('settlements')
            ->orderByDesc('due_date')
            ->get();

        $openCredit = round($creditExpenses->sum(fn (Expense $expense) => $expense->remainingAmount()), 2);
        $lastDue = $creditExpenses
            ->filter(fn (Expense $expense) => $expense->remainingAmount() > 0 && $expense->due_date)
            ->sortByDesc('due_date')
            ->first()?->due_date?->toDateString();

        $parts = [];
        foreach ($receipts as $receipt) {
            foreach ($receipt->items as $item) {
                if ($item->item_type !== 'part' || ! $item->part_id) {
                    continue;
                }
                $id = (int) $item->part_id;
                $row = $parts[$id] ?? [
                    'part_id' => $id,
                    'name' => $item->part?->name ?? 'Part',
                    'kind' => $item->part?->type,
                    'times_bought' => 0,
                    'total_qty' => 0,
                    'last_unit_cost' => null,
                    'last_buy_date' => null,
                ];
                $row['times_bought']++;
                $row['total_qty'] += (float) $item->quantity;
                $row['last_unit_cost'] = $item->unit_cost;
                $row['last_buy_date'] = $receipt->received_at?->toDateString();
                $parts[$id] = $row;
            }
        }

        $settlements = $supplier->expenses()
            ->with('settlements')
            ->get()
            ->flatMap(fn (Expense $expense) => $expense->settlements->map(fn ($settlement) => [
                'id' => $settlement->id,
                'expense_id' => $expense->id,
                'description' => $expense->description,
                'amount' => $settlement->amount,
                'settled_on' => $settlement->settled_on,
            ]))
            ->sortByDesc('settled_on')
            ->values()
            ->all();

        $orphanPurchases = $supplier->expenses()
            ->where('category', 'inventory')
            ->whereNull('stock_receipt_id')
            ->latest('expense_date')
            ->get()
            ->map(fn (Expense $expense) => [
                'id' => 'expense-'.$expense->id,
                'receipt_number' => null,
                'received_at' => $expense->expense_date?->toDateString(),
                'payment_status' => $expense->payment_status,
                'amount' => $expense->amount,
                'items_label' => $expense->description,
            ]);

        $purchases = $receipts->map(function (StockReceipt $receipt) {
            $items = $receipt->items->map(function ($item) {
                $name = $item->part?->name ?? 'Item';
                $qty = rtrim(rtrim(number_format((float) $item->quantity, 2, '.', ''), '0'), '.');

                return $name.' × '.$qty;
            })->implode(', ');

            return [
                'id' => $receipt->id,
                'receipt_number' => $receipt->receipt_number,
                'received_at' => $receipt->received_at?->toDateString(),
                'payment_status' => $receipt->payment_status,
                'amount' => $receipt->expense?->amount,
                'items_label' => $items ?: 'Stock received',
            ];
        })->concat($orphanPurchases)->sortByDesc('received_at')->values();

        return $this->moneyJson([
            ...$supplier->toArray(),
            'open_credit' => $openCredit,
            'last_due_date' => $lastDue,
            'purchase_count' => $purchases->count(),
            'part_kind_count' => count($parts),
            'purchases' => $purchases,
            'parts_bought' => array_values($parts),
            'settlements' => $settlements,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(Supplier::create($this->validated($request)), 201);
    }

    public function update(Request $request, Supplier $supplier): JsonResponse
    {
        $supplier->update($this->validated($request, $supplier));

        return response()->json($supplier->refresh());
    }

    public function destroy(Supplier $supplier): JsonResponse
    {
        abort_if($supplier->is_system, 422, 'The walk-in supplier cannot be deleted. You can rename it.');
        abort_if($supplier->receipts()->exists() || $supplier->expenses()->exists(), 422, 'This supplier has purchases and cannot be deleted.');

        $supplier->delete();

        return response()->json(null, 204);
    }

    private function validated(Request $request, ?Supplier $supplier = null): array
    {
        $isGarage = $request->user()->tenant?->business_type === BusinessTypes::GARAGE;

        return $request->validate([
            'name' => [$supplier ? 'sometimes' : 'required', 'string', 'max:255'],
            'phone' => ['nullable', 'regex:/^[0-9+() -]{7,20}$/'],
            'phone_secondary' => ['nullable', 'regex:/^[0-9+() -]{7,20}$/'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'tin' => ['nullable', 'string', 'max:50'],
            'contact_person' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:255'],
            'active' => ['sometimes', 'boolean'],
            'is_system' => $isGarage ? ['prohibited'] : ['missing'],
        ]);
    }
}
