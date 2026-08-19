<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return $this->moneyJson(Expense::with('creator')
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

        $expense->update([
            'payment_status' => Expense::STATUS_PAID,
            'settled_at' => now(),
            'updated_by' => $request->user()->id,
        ]);

        return $this->moneyJson($expense->refresh());
    }

    public function destroy(Expense $expense): JsonResponse
    {
        $expense->delete();

        return response()->json(null, 204);
    }

    private function validated(Request $request, bool $update = false): array
    {
        return $request->validate([
            'category' => [$update ? 'sometimes' : 'required', 'string', 'max:100', 'not_in:salary'],
            'description' => [$update ? 'sometimes' : 'required', 'string', 'max:255'],
            'amount' => [$update ? 'sometimes' : 'required', 'numeric', 'gt:0'],
            'expense_date' => [$update ? 'sometimes' : 'required', 'date'],
            'payment_status' => ['nullable', 'in:paid,credit'],
            'due_date' => ['nullable', 'date', 'required_if:payment_status,credit'],
        ]);
    }
}
