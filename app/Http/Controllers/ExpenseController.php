<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\ExpenseCheque;
use App\Models\ExpenseSettlement;
use App\Models\Supplier;
use App\Support\BranchQuery;
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
            ->tap(fn ($query) => BranchQuery::constrain($query))
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

        $available = $expense->availableToPay();
        abort_if($available <= 0, 422, $expense->remainingAmount() > 0
            ? 'All remaining balance is reserved by pending cheques. Clear or bounce a cheque first.'
            : 'This credit purchase is already settled.');

        $data = $request->validate([
            'amount' => ['nullable', 'numeric', 'gt:0'],
            'settled_on' => ['nullable', 'date'],
        ]);
        $amount = round((float) ($data['amount'] ?? $available), 2);
        abort_if($amount > $available, 422, 'Settle amount cannot be more than the available balance of LKR '.number_format($available, 2, '.', '').' (pending cheques are reserved).');

        $settledOn = $data['settled_on'] ?? now()->toDateString();

        $expense = DB::transaction(function () use ($expense, $amount, $settledOn, $request) {
            return $this->applySettlement($expense, $amount, $settledOn, $request->user()->id);
        });

        return $this->moneyJson([
            ...$expense->toArray(),
            'remaining' => $expense->remainingAmount(),
            'available_to_pay' => $expense->availableToPay(),
            'pending_cheque_total' => $expense->pendingChequeTotal(),
        ]);
    }

    public function issueCheque(Request $request, Expense $expense): JsonResponse
    {
        abort_unless($expense->isCredit(), 422, 'This expense is not on supplier credit.');

        $available = $expense->availableToPay();
        abort_if($available <= 0, 422, 'No available balance to issue a cheque against.');

        $data = $request->validate([
            'amount' => ['nullable', 'numeric', 'gt:0'],
            'cheque_date' => ['required', 'date'],
            'cheque_number' => ['nullable', 'string', 'max:64'],
        ]);
        $amount = round((float) ($data['amount'] ?? $available), 2);
        abort_if($amount > $available, 422, 'Cheque amount cannot be more than the available balance of LKR '.number_format($available, 2, '.', '').'.');

        $cheque = ExpenseCheque::create([
            'expense_id' => $expense->id,
            'amount' => $amount,
            'cheque_date' => $data['cheque_date'],
            'cheque_number' => $data['cheque_number'] ?? null,
            'status' => ExpenseCheque::STATUS_PENDING,
            'created_by' => $request->user()->id,
        ]);

        $expense->refresh()->load(['settlements', 'cheques', 'supplier:id,name,phone']);

        return $this->moneyJson([
            'cheque' => $this->chequePayload($cheque),
            'expense' => $this->payablePayload($expense),
        ], 201);
    }

    public function clearCheque(Request $request, ExpenseCheque $cheque): JsonResponse
    {
        abort_unless($cheque->isPending(), 422, 'Only pending cheques can be cleared.');

        $data = $request->validate([
            'cleared_on' => ['nullable', 'date'],
        ]);
        $clearedOn = $data['cleared_on'] ?? now()->toDateString();

        $expense = $cheque->expense;
        abort_unless($expense && $expense->isCredit(), 422, 'This expense is not on supplier credit.');

        $result = DB::transaction(function () use ($cheque, $expense, $clearedOn, $request) {
            $expense = $this->applySettlement($expense, (float) $cheque->amount, $clearedOn, $request->user()->id);
            $settlement = $expense->settlements()->latest('id')->first();
            $cheque->update([
                'status' => ExpenseCheque::STATUS_CLEARED,
                'settlement_id' => $settlement?->id,
                'cleared_on' => $clearedOn,
            ]);

            return [$expense->load(['settlements', 'cheques', 'supplier:id,name,phone']), $cheque->fresh()];
        });

        [$expense, $cheque] = $result;

        return $this->moneyJson([
            'cheque' => $this->chequePayload($cheque),
            'expense' => $this->payablePayload($expense),
        ]);
    }

    public function bounceCheque(ExpenseCheque $cheque): JsonResponse
    {
        abort_unless($cheque->isPending(), 422, 'Only pending cheques can be bounced.');

        $cheque->update([
            'status' => ExpenseCheque::STATUS_BOUNCED,
            'cleared_on' => null,
            'settlement_id' => null,
        ]);

        $expense = $cheque->expense?->load(['settlements', 'cheques', 'supplier:id,name,phone']);

        return $this->moneyJson([
            'cheque' => $this->chequePayload($cheque->fresh()),
            'expense' => $expense ? $this->payablePayload($expense) : null,
        ]);
    }

    public function dueCheques(): JsonResponse
    {
        $cheques = ExpenseCheque::query()
            ->due()
            ->whereHas('expense', function ($query) {
                BranchQuery::constrain($query);
            })
            ->with(['expense.supplier:id,name,phone'])
            ->orderBy('cheque_date')
            ->orderBy('id')
            ->get()
            ->map(fn (ExpenseCheque $cheque) => [
                'id' => $cheque->id,
                'expense_id' => $cheque->expense_id,
                'amount' => round((float) $cheque->amount, 2),
                'cheque_number' => $cheque->cheque_number,
                'cheque_date' => $cheque->cheque_date?->toDateString(),
                'status' => $cheque->status,
                'supplier' => $cheque->expense?->supplier?->name,
                'description' => $cheque->expense?->description,
            ])
            ->values()
            ->all();

        return $this->moneyJson([
            'count' => count($cheques),
            'items' => $cheques,
        ]);
    }

    public function destroy(Expense $expense): JsonResponse
    {
        $expense->delete();

        return response()->json(null, 204);
    }

    private function applySettlement(Expense $expense, float $amount, string $settledOn, int $userId): Expense
    {
        ExpenseSettlement::create([
            'expense_id' => $expense->id,
            'amount' => $amount,
            'settled_on' => $settledOn,
            'created_by' => $userId,
        ]);

        $paid = round((float) $expense->amount_paid + $amount, 2);
        $fullyPaid = $paid + 0.00001 >= (float) $expense->amount;
        $expense->update([
            'amount_paid' => min($paid, (float) $expense->amount),
            'payment_status' => $fullyPaid ? Expense::STATUS_PAID : Expense::STATUS_CREDIT,
            'settled_at' => $fullyPaid ? $settledOn : null,
            'updated_by' => $userId,
        ]);

        return $expense->refresh()->load(['settlements', 'cheques']);
    }

    /** @return array<string, mixed> */
    private function chequePayload(ExpenseCheque $cheque): array
    {
        return [
            'id' => $cheque->id,
            'expense_id' => $cheque->expense_id,
            'amount' => round((float) $cheque->amount, 2),
            'cheque_number' => $cheque->cheque_number,
            'cheque_date' => $cheque->cheque_date?->toDateString(),
            'status' => $cheque->status,
            'settlement_id' => $cheque->settlement_id,
            'cleared_on' => $cheque->cleared_on?->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    private function payablePayload(Expense $expense): array
    {
        $pending = $expense->cheques
            ->where('status', ExpenseCheque::STATUS_PENDING)
            ->sortBy('cheque_date')
            ->values()
            ->map(fn (ExpenseCheque $row) => $this->chequePayload($row))
            ->all();

        return [
            'id' => $expense->id,
            'description' => $expense->description,
            'supplier_id' => $expense->supplier_id,
            'supplier' => $expense->supplier?->name,
            'supplier_phone' => $expense->supplier?->phone,
            'amount' => round((float) $expense->amount, 2),
            'amount_paid' => round((float) $expense->amount_paid, 2),
            'remaining' => $expense->remainingAmount(),
            'pending_cheque_total' => $expense->pendingChequeTotal(),
            'available_to_pay' => $expense->availableToPay(),
            'expense_date' => $expense->expense_date?->toDateString(),
            'due_date' => $expense->due_date?->toDateString(),
            'category' => $expense->category,
            'payment_status' => $expense->payment_status,
            'pending_cheques' => $pending,
            'settlements' => $expense->settlements
                ->sortBy('settled_on')
                ->values()
                ->map(fn (ExpenseSettlement $row) => [
                    'id' => $row->id,
                    'amount' => round((float) $row->amount, 2),
                    'settled_on' => $row->settled_on?->toDateString(),
                ])
                ->all(),
        ];
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
