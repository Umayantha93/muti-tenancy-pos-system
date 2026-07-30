<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\BillItem;
use App\Models\BillPayment;
use App\Models\Part;
use App\Models\Expense;
use App\Models\Payroll;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $billing = $request->user()->canAccessFeature('billing');
        $inventory = $request->user()->canAccessFeature('parts_inventory');
        $finance = $request->user()->canAccessFeature('balance_sheet');
        $monthlyIncome = $finance ? (float) BillPayment::whereYear('paid_at', now()->year)->whereMonth('paid_at', now()->month)->sum('amount')
            + (float) BillItem::where('type', 'advance')->whereYear('created_at', now()->year)->whereMonth('created_at', now()->month)->sum('line_total') : null;
        $monthlyExpenses = $finance ? (float) Expense::whereYear('expense_date', now()->year)->whereMonth('expense_date', now()->month)->sum('amount')
            + (float) Payroll::where('year', now()->year)->where('month', now()->month)->sum('net_salary') : null;

        return response()->json([
            'features' => $request->user()->accessibleFeatureKeys(),
            'today_income' => $billing ? (float) BillPayment::whereDate('paid_at', today())->sum('amount')
                + (float) BillItem::where('type', 'advance')->whereDate('created_at', today())->sum('line_total') : null,
            'open_bills' => $billing ? Bill::whereIn('status', ['open', 'partially_paid'])->count() : null,
            'low_stock_parts' => $inventory ? Part::where('stock_qty', '<=', 5)->count() : null,
            'monthly_income' => $monthlyIncome,
            'monthly_expenses' => $monthlyExpenses,
            'monthly_profit' => $finance ? $monthlyIncome - $monthlyExpenses : null,
            'recent_bills' => $billing ? Bill::with(['customer', 'vehicle'])->latest()->limit(5)->get() : [],
        ]);
    }
}
