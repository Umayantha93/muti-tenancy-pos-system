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
        $monthlyIncome = $finance ? (float) BillPayment::whereYear('paid_at', now()->year)->whereMonth('paid_at', now()->month)->sum('amount')
            + (float) BillItem::where('type', 'advance')->whereYear('created_at', now()->year)->whereMonth('created_at', now()->month)->sum('line_total') : null;
        $monthlyExpenses = $finance ? (float) Expense::whereYear('expense_date', now()->year)->whereMonth('expense_date', now()->month)->sum('amount')
            + (float) Payroll::where('year', now()->year)->where('month', now()->month)->sum('net_salary') : null;

        $lowStock = null;
        if ($user->canAccessFeature('parts_inventory')) {
            $lowStock = Part::where('stock_qty', '<=', 5)->count();
        } elseif ($user->canAccessFeature('product_catalog')) {
            $lowStock = Product::where('stock_qty', '<=', 5)->count();
        }

        return $this->moneyJson([
            'features' => $user->accessibleFeatureKeys(),
            'business_type' => $type,
            'today_income' => $billing ? (float) BillPayment::whereDate('paid_at', today())->sum('amount')
                + (float) BillItem::where('type', 'advance')->whereDate('created_at', today())->sum('line_total') : null,
            'open_bills' => $billing ? Bill::whereIn('status', ['open', 'partially_paid'])->count() : null,
            'low_stock_parts' => $inventory ? $lowStock : null,
            'upcoming_bookings' => $user->canAccessFeature('photo_bookings')
                ? PhotoBooking::with(['customer', 'package'])->where('scheduled_at', '>=', now())->whereNotIn('status', ['cancelled', 'delivered'])->orderBy('scheduled_at')->limit(5)->get()
                : [],
            'active_stays' => $user->canAccessFeature('cottage_stays')
                ? CottageStay::with(['customer', 'room'])->whereIn('status', ['reserved', 'checked_in'])->orderBy('check_in')->limit(5)->get()
                : [],
            'monthly_income' => $monthlyIncome,
            'monthly_expenses' => $monthlyExpenses,
            'monthly_profit' => $finance ? $monthlyIncome - $monthlyExpenses : null,
            'recent_bills' => $billing ? Bill::with(['customer', 'vehicle'])->latest()->limit(5)->get() : [],
        ]);
    }
}
