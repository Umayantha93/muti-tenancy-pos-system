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
        $summary = $this->summary($month, $year);

        return $this->moneyJson([
            'period' => ['month' => $month, 'year' => $year],
            ...$summary,
            'accounts' => $this->accounts($month, $year),
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

    /**
     * Chronological ledger for the selected month with running balance.
     *
     * @return list<array{
     *   date: string,
     *   description: string,
     *   reference: string|null,
     *   category: string,
     *   type: 'income'|'expense',
     *   debit: float,
     *   credit: float,
     *   balance: float
     * }>
     */
    private function accounts(int $month, int $year): array
    {
        $rows = collect();

        BillPayment::query()
            ->with('bill:id,bill_number')
            ->whereYear('paid_at', $year)
            ->whereMonth('paid_at', $month)
            ->orderBy('paid_at')
            ->get()
            ->each(function (BillPayment $payment) use ($rows) {
                $rows->push([
                    'date' => $payment->paid_at?->toDateString(),
                    'sort_at' => $payment->paid_at?->format('Y-m-d H:i:s') ?? '',
                    'description' => 'Payment received'.($payment->method ? ' · '.str_replace('_', ' ', $payment->method) : ''),
                    'reference' => $payment->reference ?: ($payment->bill?->bill_number),
                    'category' => 'Sales Revenue',
                    'type' => 'income',
                    'debit' => 0.0,
                    'credit' => (float) $payment->amount,
                ]);
            });

        BillItem::query()
            ->with('bill:id,bill_number')
            ->where('type', 'advance')
            ->whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->orderBy('created_at')
            ->get()
            ->each(function (BillItem $item) use ($rows) {
                $rows->push([
                    'date' => $item->created_at?->toDateString(),
                    'sort_at' => $item->created_at?->format('Y-m-d H:i:s') ?? '',
                    'description' => $item->description ?: 'Customer advance',
                    'reference' => $item->bill?->bill_number,
                    'category' => 'Advances',
                    'type' => 'income',
                    'debit' => 0.0,
                    'credit' => (float) $item->line_total,
                ]);
            });

        Expense::query()
            ->whereYear('expense_date', $year)
            ->whereMonth('expense_date', $month)
            ->orderBy('expense_date')
            ->get()
            ->each(function (Expense $expense) use ($rows) {
                $rows->push([
                    'date' => $expense->expense_date?->toDateString() ?? $expense->expense_date,
                    'sort_at' => ($expense->expense_date?->format('Y-m-d') ?? '').' 12:00:00',
                    'description' => $expense->description,
                    'reference' => null,
                    'category' => ucwords(str_replace('_', ' ', $expense->category)),
                    'type' => 'expense',
                    'debit' => (float) $expense->amount,
                    'credit' => 0.0,
                ]);
            });

        Payroll::query()
            ->with('employee:id,name')
            ->where('year', $year)
            ->where('month', $month)
            ->orderBy('id')
            ->get()
            ->each(function (Payroll $payroll) use ($rows, $year, $month) {
                $day = now()->setDate($year, $month, 1)->endOfMonth()->toDateString();
                $rows->push([
                    'date' => $day,
                    'sort_at' => $day.' 23:59:00',
                    'description' => 'Salary · '.($payroll->employee?->name ?? 'Employee'),
                    'reference' => sprintf('PAY-%04d-%02d-%d', $year, $month, $payroll->id),
                    'category' => 'Salaries',
                    'type' => 'expense',
                    'debit' => (float) $payroll->net_salary,
                    'credit' => 0.0,
                ]);
            });

        $balance = 0.0;

        return $rows
            ->sortBy([
                ['sort_at', 'asc'],
                ['type', 'desc'], // income before expense on same stamp when useful
            ])
            ->values()
            ->map(function (array $row) use (&$balance) {
                $balance = round($balance + $row['credit'] - $row['debit'], 2);
                unset($row['sort_at']);
                $row['debit'] = round($row['debit'], 2);
                $row['credit'] = round($row['credit'], 2);
                $row['balance'] = $balance;

                return $row;
            })
            ->all();
    }
}
