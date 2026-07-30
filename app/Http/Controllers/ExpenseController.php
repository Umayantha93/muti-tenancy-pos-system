<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExpenseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(Expense::with('creator')
            ->when($request->filled('category'), fn ($query) => $query->where('category', $request->string('category')))
            ->when($request->filled('month'), fn ($query) => $query->whereMonth('expense_date', $request->integer('month')))
            ->when($request->filled('year'), fn ($query) => $query->whereYear('expense_date', $request->integer('year')))
            ->latest('expense_date')->paginate(30));
    }

    public function store(Request $request): JsonResponse
    {
        $expense = Expense::create([...$this->validated($request), 'created_by' => $request->user()->id]);
        return response()->json($expense, 201);
    }

    public function update(Request $request, Expense $expense): JsonResponse
    {
        $expense->update([...$this->validated($request, true), 'updated_by' => $request->user()->id]);
        return response()->json($expense->refresh());
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
        ]);
    }
}
