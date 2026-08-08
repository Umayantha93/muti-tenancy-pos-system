<?php

namespace App\Http\Controllers;

use App\Models\BillItem;
use App\Models\BillPayment;
use App\Models\Expense;
use App\Models\Payroll;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BalanceSheetController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'month' => ['nullable', 'integer', 'between:1,12'],
            'year' => ['required', 'integer', 'between:2020,2100'],
        ]);
        $month = $data['month'] ?? now()->month;
        $year = $data['year'];

        return $this->moneyJson([
            'period' => ['month' => $month, 'year' => $year],
            ...$this->summary($month, $year),
            'yearly_trend' => collect(range(1, 12))->map(fn ($trendMonth) => [
                'month' => $trendMonth,
                ...$this->summary($trendMonth, $year, false),
            ])->all(),
        ]);
    }

    private function summary(int $month, int $year, bool $withBreakdown = true): array
    {
        $payments = (float) BillPayment::whereYear('paid_at', $year)->whereMonth('paid_at', $month)->sum('amount');
        $advances = (float) BillItem::where('type', 'advance')->whereYear('created_at', $year)->whereMonth('created_at', $month)->sum('line_total');
        $manualExpenses = Expense::whereYear('expense_date', $year)->whereMonth('expense_date', $month);
        $manualTotal = (float) (clone $manualExpenses)->sum('amount');
        $salaryTotal = (float) Payroll::where('year', $year)->where('month', $month)->sum('net_salary');
        $income = $payments + $advances;
        $expenses = $manualTotal + $salaryTotal;
        $result = ['income' => $income, 'expenses' => $expenses, 'net_profit' => $income - $expenses];

        if ($withBreakdown) {
            $result['expense_breakdown'] = (clone $manualExpenses)
                ->selectRaw('category, SUM(amount) as total')->groupBy('category')
                ->pluck('total', 'category')->map(fn ($amount) => (float) $amount)
                ->put('salary', $salaryTotal);
        }

        return $result;
    }
}
