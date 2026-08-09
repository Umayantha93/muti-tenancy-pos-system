<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\BillItem;
use App\Models\BillPayment;
use App\Models\CottageStay;
use App\Models\Expense;
use App\Models\Part;
use App\Models\Payroll;
use App\Models\PhotoBooking;
use App\Models\Product;
use App\Services\MonetaryView;
use App\Support\BusinessTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $type = $user->tenant?->business_type ?? BusinessTypes::GARAGE;
        $billing = $user->canAccessFeature('billing');
        $inventory = $user->canAccessFeature('parts_inventory') || $user->canAccessFeature('product_catalog');
        $finance = $user->canAccessFeature('balance_sheet');
        $view = MonetaryView::for($user);

        $monthlyIncome = $finance ? $this->incomeFor(
            $view,
            BillPayment::query()->with(['bill.items'])->whereYear('paid_at', now()->year)->whereMonth('paid_at', now()->month)->get(),
            BillItem::query()->with(['bill.items'])->where('type', 'advance')->whereYear('created_at', now()->year)->whereMonth('created_at', now()->month)->get(),
        ) : null;

        $monthlyExpensesRaw = $finance
            ? (float) Expense::whereYear('expense_date', now()->year)->whereMonth('expense_date', now()->month)->sum('amount')
                + (float) Payroll::where('year', now()->year)->where('month', now()->month)->sum('net_salary')
            : null;
        $monthlyExpenses = $finance
            ? ($view->active() ? $view->scaleExpense((float) $monthlyExpensesRaw) : $monthlyExpensesRaw)
            : null;

        $lowStock = null;
        if ($user->canAccessFeature('parts_inventory')) {
            $lowStock = Part::where('stock_qty', '<=', 5)->count();
        } elseif ($user->canAccessFeature('product_catalog')) {
            $lowStock = Product::where('stock_qty', '<=', 5)->count();
        }

        $todayIncome = $billing ? $this->incomeFor(
            $view,
            BillPayment::query()->with(['bill.items'])->whereDate('paid_at', today())->get(),
            BillItem::query()->with(['bill.items'])->where('type', 'advance')->whereDate('created_at', today())->get(),
        ) : null;

        $recentBills = $billing
            ? Bill::with(['customer', 'vehicle', 'items', 'payments'])->latest()->limit(5)->get()
            : collect();

        return response()->json([
            'features' => $user->accessibleFeatureKeys(),
            'business_type' => $type,
            'today_income' => $todayIncome,
            'open_bills' => $billing ? Bill::whereIn('status', ['open', 'partially_paid'])->count() : null,
            'low_stock_parts' => $inventory ? $lowStock : null,
            'upcoming_bookings' => $user->canAccessFeature('photo_bookings')
                ? $view->transform(
                    PhotoBooking::with(['customer', 'package'])->where('scheduled_at', '>=', now())->whereNotIn('status', ['cancelled', 'delivered'])->orderBy('scheduled_at')->limit(5)->get()
                )
                : [],
            'active_stays' => $user->canAccessFeature('cottage_stays')
                ? $view->transform(
                    CottageStay::with(['customer', 'room'])->whereIn('status', ['reserved', 'checked_in'])->orderBy('check_in')->limit(5)->get()
                )
                : [],
            'monthly_income' => $monthlyIncome,
            'monthly_expenses' => $monthlyExpenses,
            'monthly_profit' => $finance ? round((float) $monthlyIncome - (float) $monthlyExpenses, 2) : null,
            'recent_bills' => $view->transform($recentBills),
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, BillPayment>  $payments
     * @param  \Illuminate\Support\Collection<int, BillItem>  $advances
     */
    private function incomeFor(MonetaryView $view, $payments, $advances): float
    {
        if (! $view->active()) {
            return round(
                (float) $payments->sum('amount') + (float) $advances->sum('line_total'),
                2
            );
        }

        $paymentTotal = $payments->sum(
            fn (BillPayment $payment) => $view->scaleReceipt(
                (float) $payment->amount,
                $payment->bill?->items ?? []
            )
        );
        $advanceTotal = $advances->sum(
            fn (BillItem $item) => $view->scaleReceipt(
                (float) $item->line_total,
                $item->bill?->items ?? []
            )
        );

        return round((float) $paymentTotal + (float) $advanceTotal, 2);
    }
}
