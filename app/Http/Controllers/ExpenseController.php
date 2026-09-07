<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\ExpenseSettlement;
use App\Models\Supplier;
use App\Support\BusinessTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ExpenseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return $this->moneyJson(Expense::with('creator')
            ->tap(fn ($query) => \App\Support\BranchQuery::constrain($query))
            ->when($request->filled('category'), fn ($query) => $query->where('category', $request->string('category')))
            ->when($request->filled('month'), fn ($query) => $query->whereMonth('expense_date', $request->integer('month')))
            ->when($request->filled('year'), fn ($query) => $query->whereYear('expense_date', $request->integer('year')))
            ->when($request->filled('payment_status'), fn ($query) => $query->where('payment_status', $request->string('payment_status')))
            ->latest('expense_date')->paginate(30));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $paid = ($data['payment_status'] ?? Expense::STATUS_PAID) !== Expense::STATUS_CREDIT;
        $tenant = $request->user()->tenant;
        if (($data['category'] ?? null) === 'inventory' && $tenant?->business_type === BusinessTypes::GARAGE) {
            $data['supplier_id'] = $data['supplier_id']
                ?? Supplier::ensureWalkInFor((int) $tenant->id)->id;
        }
        $expense = Expense::create([
            ...$data,
            'payment_status' => $paid ? Expense::STATUS_PAID : Expense::STATUS_CREDIT,
            'due_date' => $paid ? null : ($data['due_date'] ?? null),
            'settled_at' => $paid ? ($data['expense_date'] ?? now()->toDateString()) : null,
            'created_by' => $request->user()->id,
        ]);

        return response()->json($expense, 201);
    }

    public function update(Request $request, Expense $expense): JsonResponse
    {
        $expense->update([...$this->validated($request, true), 'updated_by' => $request->user()->id]);

        return response()->json($expense->refresh());
    }

    public function settle(Request $request, Expense $expense): JsonResponse
    {
        abort_unless($expense->isCredit(), 422, 'This expense is not on supplier credit.');

        $remaining = $expense->remainingAmount();
        abort_if($remaining <= 0, 422, 'This credit purchase is already settled.');

        $data = $request->validate([
            'amount' => ['nullable', 'numeric', 'gt:0'],
            'settled_on' => ['nullable', 'date'],
        ]);
        $amount = round((float) ($data['amount'] ?? $remaining), 2);
        abort_if($amount > $remaining, 422, 'Settle amount cannot be more than the remaining balance of LKR '.number_format($remaining, 2, '.', '').'.');

        $settledOn = $data['settled_on'] ?? now()->toDateString();

        $expense = DB::transaction(function () use ($expense, $amount, $settledOn, $request) {
            ExpenseSettlement::create([
                'expense_id' => $expense->id,
                'amount' => $amount,
                'settled_on' => $settledOn,
                'created_by' => $request->user()->id,
            ]);

            $paid = round((float) $expense->amount_paid + $amount, 2);
            $fullyPaid = $paid + 0.00001 >= (float) $expense->amount;
            $expense->update([
                'amount_paid' => min($paid, (float) $expense->amount),
                'payment_status' => $fullyPaid ? Expense::STATUS_PAID : Expense::STATUS_CREDIT,
                'settled_at' => $fullyPaid ? $settledOn : null,
                'updated_by' => $request->user()->id,
            ]);

            return $expense->refresh()->load('settlements');
        });

        return $this->moneyJson([
            ...$expense->toArray(),
            'remaining' => $expense->remainingAmount(),
        ]);
    }

    public function destroy(Expense $expense): JsonResponse
    {
        $expense->delete();

        return response()->json(null, 204);
    }

    private function validated(Request $request, bool $update = false): array
    {
        $tenantId = $request->user()->tenant_id;

        return $request->validate([
            'category' => [$update ? 'sometimes' : 'required', 'string', 'max:100', 'not_in:salary'],
            'description' => [$update ? 'sometimes' : 'required', 'string', 'max:255'],
            'amount' => [$update ? 'sometimes' : 'required', 'numeric', 'gt:0'],
            'expense_date' => [$update ? 'sometimes' : 'required', 'date'],
            'payment_status' => ['nullable', 'in:paid,credit'],
            'due_date' => ['nullable', 'date', 'required_if:payment_status,credit'],
            'supplier_id' => [
                'nullable',
                Rule::exists('suppliers', 'id')->where('tenant_id', $tenantId),
            ],
        ]);
    }
}
