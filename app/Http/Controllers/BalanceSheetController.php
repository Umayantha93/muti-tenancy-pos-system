<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\BillItem;
use App\Models\BillPayment;
use App\Models\Expense;
use App\Models\ExpenseSettlement;
use App\Models\Payroll;
use App\Services\BillProfitAnalyzer;
use App\Services\MonetaryView;
use App\Support\BranchQuery;
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
        $view = MonetaryView::for();
        $summary = $this->summary($month, $year, $view, true);

        return response()->json([
            'period' => ['month' => $month, 'year' => $year],
            ...$summary,
            'accounts' => $this->accounts($month, $year, $view),
            'inventory_payables' => $this->inventoryPayables($view),
            'bill_receivables' => $this->billReceivables($view),
            'yearly_trend' => collect(range(1, 12))->map(fn ($trendMonth) => [
                'month' => $trendMonth,
                ...$this->summary($trendMonth, $year, $view, false),
            ])->all(),
        ]);
    }

    private function summary(int $month, int $year, MonetaryView $view, bool $withBreakdown = true): array
    {
        if ($view->active()) {
            $payments = BranchQuery::constrainViaBill(BillPayment::query()
                ->with(['bill.items']))
                ->whereYear('paid_at', $year)
                ->whereMonth('paid_at', $month)
                ->get()
                ->sum(fn (BillPayment $payment) => $view->scaleReceipt(
                    (float) $payment->amount,
                    $payment->bill?->items ?? []
                ));

            $advances = BranchQuery::constrainViaBill(BillItem::query()
                ->with(['bill.items']))
                ->where('type', 'advance')
                ->whereYear('created_at', $year)
                ->whereMonth('created_at', $month)
                ->get()
                ->sum(fn (BillItem $item) => $view->scaleReceipt(
                    (float) $item->line_total,
                    $item->bill?->items ?? []
                ));

            $manualExpenses = BranchQuery::constrain(Expense::postedIn($month, $year));
            $manualTotal = (float) (clone $manualExpenses)->sum('amount') + $this->settlementsTotal($month, $year);
            $salaryTotal = (float) BranchQuery::constrain(Payroll::query())->where('year', $year)->where('month', $month)->sum('net_salary');
            $income = round((float) $payments + (float) $advances, 2);
            $expenses = round($view->scaleExpense($manualTotal) + $view->scaleExpense($salaryTotal), 2);
            $result = [
                'income' => $income,
                'expenses' => $expenses,
                'net_profit' => round($income - $expenses, 2),
            ];

            if ($withBreakdown) {
                $result['expense_breakdown'] = (clone $manualExpenses)
                    ->selectRaw('category, SUM(amount) as total')->groupBy('category')
                    ->pluck('total', 'category')
                    ->map(fn ($amount) => $view->scaleExpense((float) $amount))
                    ->put('salary', $view->scaleExpense($salaryTotal));
                $settlements = $this->settlementsTotal($month, $year);
                if ($settlements > 0) {
                    $result['expense_breakdown']['inventory'] = round(
                        (float) ($result['expense_breakdown']['inventory'] ?? 0) + $view->scaleExpense($settlements),
                        2
                    );
                }
            }

            return $result;
        }

        $payments = (float) BranchQuery::constrainViaBill(BillPayment::query())->whereYear('paid_at', $year)->whereMonth('paid_at', $month)->sum('amount');
        $advances = (float) BranchQuery::constrainViaBill(BillItem::query())->where('type', 'advance')->whereYear('created_at', $year)->whereMonth('created_at', $month)->sum('line_total');
        $manualExpenses = BranchQuery::constrain(Expense::postedIn($month, $year));
        $manualTotal = (float) (clone $manualExpenses)->sum('amount') + $this->settlementsTotal($month, $year);
        $salaryTotal = (float) BranchQuery::constrain(Payroll::query())->where('year', $year)->where('month', $month)->sum('net_salary');
        $income = $payments + $advances;
        $expenses = $manualTotal + $salaryTotal;
        $result = ['income' => $income, 'expenses' => $expenses, 'net_profit' => $income - $expenses];

        if ($withBreakdown) {
            $result['expense_breakdown'] = (clone $manualExpenses)
                ->selectRaw('category, SUM(amount) as total')->groupBy('category')
                ->pluck('total', 'category')->map(fn ($amount) => (float) $amount)
                ->put('salary', $salaryTotal);
            $settlements = $this->settlementsTotal($month, $year);
            if ($settlements > 0) {
                $result['expense_breakdown']['inventory'] = round(
                    (float) ($result['expense_breakdown']['inventory'] ?? 0) + $settlements,
                    2
                );
            }
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
     *   type: 'income'|'expense'|'payable'|'bill',
     *   debit: float,
     *   credit: float,
     *   balance: float
     * }>
     */
    private function accounts(int $month, int $year, MonetaryView $view): array
    {
        $rows = collect();

        BillPayment::query()
            ->with(['bill:id,bill_number', 'bill.items'])
            ->tap(fn ($query) => BranchQuery::constrainViaBill($query))
            ->whereYear('paid_at', $year)
            ->whereMonth('paid_at', $month)
            ->orderBy('paid_at')
            ->get()
            ->each(function (BillPayment $payment) use ($rows, $view) {
                $amount = $view->active()
                    ? $view->scaleReceipt((float) $payment->amount, $payment->bill?->items ?? [])
                    : (float) $payment->amount;

                $rows->push([
                    'date' => $payment->paid_at?->toDateString(),
                    'sort_at' => $payment->paid_at?->format('Y-m-d H:i:s') ?? '',
                    'description' => 'Payment received'.($payment->method ? ' · '.str_replace('_', ' ', $payment->method) : ''),
                    'reference' => $payment->reference ?: ($payment->bill?->bill_number),
                    'category' => 'Sales Revenue',
                    'type' => 'income',
                    'debit' => 0.0,
                    'credit' => $amount,
                ]);
            });

        BillItem::query()
            ->with(['bill:id,bill_number', 'bill.items'])
            ->tap(fn ($query) => BranchQuery::constrainViaBill($query))
            ->where('type', 'advance')
            ->whereYear('created_at', $year)
            ->whereMonth('created_at', $month)
            ->orderBy('created_at')
            ->get()
            ->each(function (BillItem $item) use ($rows, $view) {
                $amount = $view->active()
                    ? $view->scaleReceipt((float) $item->line_total, $item->bill?->items ?? [])
                    : (float) $item->line_total;

                $rows->push([
                    'date' => $item->created_at?->toDateString(),
                    'sort_at' => $item->created_at?->format('Y-m-d H:i:s') ?? '',
                    'description' => $item->description ?: 'Customer advance',
                    'reference' => $item->bill?->bill_number,
                    'category' => 'Advances',
                    'type' => 'income',
                    'debit' => 0.0,
                    'credit' => $amount,
                ]);
            });

        Expense::query()
            ->postedIn($month, $year)
            ->tap(fn ($query) => BranchQuery::constrain($query))
            ->orderBy('expense_date')
            ->get()
            ->each(function (Expense $expense) use ($rows, $view) {
                $amount = $view->active()
                    ? $view->scaleExpense((float) $expense->amount)
                    : (float) $expense->amount;
                $posted = $expense->settled_at?->toDateString() ?? $expense->expense_date?->toDateString();

                $rows->push([
                    'date' => $posted,
                    'sort_at' => ($expense->settled_at?->format('Y-m-d H:i:s') ?? (($expense->expense_date?->format('Y-m-d') ?? '').' 12:00:00')),
                    'description' => $expense->description,
                    'reference' => null,
                    'category' => ucwords(str_replace('_', ' ', $expense->category)),
                    'type' => 'expense',
                    'debit' => $amount,
                    'credit' => 0.0,
                ]);
            });

        ExpenseSettlement::query()
            ->with('expense:id,description,category,branch_id')
            ->whereHas('expense', function ($query) {
                $id = BranchQuery::idForRead();
                if ($id !== null) {
                    $query->where('branch_id', $id);
                }
            })
            ->whereYear('settled_on', $year)
            ->whereMonth('settled_on', $month)
            ->orderBy('settled_on')
            ->get()
            ->each(function (ExpenseSettlement $settlement) use ($rows, $view) {
                $amount = $view->active()
                    ? $view->scaleExpense((float) $settlement->amount)
                    : (float) $settlement->amount;

                $rows->push([
                    'date' => $settlement->settled_on?->toDateString(),
                    'sort_at' => ($settlement->settled_on?->format('Y-m-d') ?? '').' 12:15:00',
                    'description' => ($settlement->expense?->description ?: 'Supplier credit').' · settlement',
                    'reference' => null,
                    'category' => ucwords(str_replace('_', ' ', $settlement->expense?->category ?: 'inventory')),
                    'type' => 'expense',
                    'debit' => $amount,
                    'credit' => 0.0,
                ]);
            });

        Expense::query()
            ->credit()
            ->tap(fn ($query) => BranchQuery::constrain($query))
            ->whereYear('expense_date', $year)
            ->whereMonth('expense_date', $month)
            ->orderBy('expense_date')
            ->get()
            ->each(function (Expense $expense) use ($rows, $view) {
                $remaining = $view->active()
                    ? $view->scaleExpense($expense->remainingAmount())
                    : $expense->remainingAmount();

                $rows->push([
                    'date' => $expense->expense_date?->toDateString() ?? $expense->expense_date,
                    'sort_at' => ($expense->expense_date?->format('Y-m-d') ?? '').' 12:30:00',
                    'description' => $expense->description.' · supplier credit (not in profit until paid)',
                    'reference' => $expense->due_date?->toDateString(),
                    'category' => 'Inventory payable',
                    'type' => 'payable',
                    'debit' => $remaining,
                    'credit' => 0.0,
                ]);
            });

        $analyzer = app(BillProfitAnalyzer::class);
        BranchQuery::constrain(Bill::query())
            ->with(['items.part'])
            ->whereNotNull('closed_at')
            ->whereYear('closed_at', $year)
            ->whereMonth('closed_at', $month)
            ->orderBy('closed_at')
            ->get()
            ->each(function (Bill $bill) use ($rows, $analyzer) {
                $summary = $analyzer->summarize($bill);
                $rows->push([
                    'date' => $bill->closed_at?->toDateString(),
                    'sort_at' => $bill->closed_at?->format('Y-m-d H:i:s') ?? '',
                    'description' => sprintf(
                        'Bill closed · revenue %s · COGS %s · profit %s',
                        number_format($summary['revenue'], 2, '.', ''),
                        number_format($summary['cogs'], 2, '.', ''),
                        number_format($summary['profit'], 2, '.', '')
                    ),
                    'reference' => $bill->bill_number,
                    'category' => $summary['billing_type'] === 'credit' ? 'Credit bill' : 'Bill profit',
                    'type' => 'bill',
                    'debit' => 0.0,
                    'credit' => 0.0,
                ]);
            });

        BranchQuery::constrain(Payroll::query())
            ->with('employee:id,name')
            ->where('year', $year)
            ->where('month', $month)
            ->orderBy('id')
            ->get()
            ->each(function (Payroll $payroll) use ($rows, $view, $year, $month) {
                $amount = $view->active()
                    ? $view->scaleExpense((float) $payroll->net_salary)
                    : (float) $payroll->net_salary;
                $day = now()->setDate($year, $month, 1)->endOfMonth()->toDateString();
                $rows->push([
                    'date' => $day,
                    'sort_at' => $day.' 23:59:00',
                    'description' => 'Salary · '.($payroll->employee?->name ?? 'Employee'),
                    'reference' => sprintf('PAY-%04d-%02d-%d', $year, $month, $payroll->id),
                    'category' => 'Salaries',
                    'type' => 'expense',
                    'debit' => $amount,
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
                if (in_array($row['type'], ['income', 'expense'], true)) {
                    $balance = round($balance + $row['credit'] - $row['debit'], 2);
                }
                unset($row['sort_at']);
                $row['debit'] = round($row['debit'], 2);
                $row['credit'] = round($row['credit'], 2);
                $row['balance'] = $balance;

                return $row;
            })
            ->all();
    }

    /**
     * @return array{payables_total: float, items: list<array<string, mixed>>}
     */
    private function inventoryPayables(MonetaryView $view): array
    {
        $items = Expense::query()
            ->credit()
            ->tap(fn ($query) => BranchQuery::constrain($query))
            ->with('settlements')
            ->orderBy('due_date')
            ->orderBy('expense_date')
            ->get()
            ->map(function (Expense $expense) use ($view) {
                $remaining = $view->active()
                    ? $view->scaleExpense($expense->remainingAmount())
                    : $expense->remainingAmount();
                $original = $view->active()
                    ? $view->scaleExpense((float) $expense->amount)
                    : (float) $expense->amount;
                $paid = $view->active()
                    ? $view->scaleExpense((float) $expense->amount_paid)
                    : (float) $expense->amount_paid;

                return [
                    'id' => $expense->id,
                    'description' => $expense->description,
                    'amount' => round($original, 2),
                    'amount_paid' => round($paid, 2),
                    'remaining' => round($remaining, 2),
                    'expense_date' => $expense->expense_date?->toDateString(),
                    'due_date' => $expense->due_date?->toDateString(),
                    'category' => $expense->category,
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
            })
            ->all();

        return [
            'payables_total' => round(collect($items)->sum('remaining'), 2),
            'items' => $items,
        ];
    }

    private function settlementsTotal(int $month, int $year): float
    {
        return (float) ExpenseSettlement::query()
            ->whereHas('expense', function ($query) {
                $id = BranchQuery::idForRead();
                if ($id !== null) {
                    $query->where('branch_id', $id);
                }
            })
            ->whereYear('settled_on', $year)
            ->whereMonth('settled_on', $month)
            ->sum('amount');
    }

    /**
     * @return array{receivables_total: float, count: int}
     */
    private function billReceivables(MonetaryView $view): array
    {
        $bills = BranchQuery::constrain(Bill::query())->where('status', 'owe_in')->get(['balance_due']);
        $total = (float) $bills->sum('balance_due');

        return [
            'receivables_total' => $view->active() ? $view->scaleExpense($total) : round($total, 2),
            'count' => $bills->count(),
        ];
    }
}
