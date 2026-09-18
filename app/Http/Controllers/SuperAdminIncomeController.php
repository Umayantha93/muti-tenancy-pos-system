<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\TenantFeePayment;
use App\Models\TenantSetupFeePayment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SuperAdminIncomeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => ['nullable', 'integer', 'between:2020,2100'],
            'month' => ['nullable', 'integer', 'between:1,12'],
        ]);
        $year = (int) ($data['year'] ?? now()->year);
        $month = isset($data['month']) ? (int) $data['month'] : null;

        $payments = TenantFeePayment::query()
            ->with('tenant:id,business_name,status,payment_plan,plan_amount')
            ->where('year', $year)
            ->when($month, fn ($query) => $query->where('month', $month))
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->get();

        $setupPayments = TenantSetupFeePayment::query()
            ->with('tenant:id,business_name,status,setup_fee_amount')
            ->whereYear('paid_at', $year)
            ->when($month, fn ($query) => $query->whereMonth('paid_at', $month))
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->get();

        $monthRows = collect(range(1, 12))->map(function (int $rowMonth) use ($payments, $setupPayments, $year) {
            $feeRows = $payments->where('month', $rowMonth);
            $setupRows = $setupPayments->filter(fn (TenantSetupFeePayment $payment) => (int) $payment->paid_at?->month === $rowMonth);

            return [
                'month' => $rowMonth,
                'period' => sprintf('%04d-%02d', $year, $rowMonth),
                'label' => now()->setMonth($rowMonth)->format('M'),
                'collected' => round((float) $feeRows->sum('amount') + (float) $setupRows->sum('amount'), 2),
                'count' => $feeRows->count() + $setupRows->count(),
            ];
        })->values();

        $byTenant = $payments
            ->concat($setupPayments)
            ->groupBy('tenant_id')
            ->map(function ($rows) {
                $tenant = $rows->first()?->tenant;

                return [
                    'tenant_id' => $rows->first()?->tenant_id,
                    'business_name' => $tenant?->business_name ?? 'Removed business',
                    'collected' => round((float) $rows->sum('amount'), 2),
                    'count' => $rows->count(),
                ];
            })
            ->sortByDesc('collected')
            ->values();

        $outstandingMonth = $month ?? ($year === (int) now()->year ? (int) now()->month : null);
        $paidTenantIds = $outstandingMonth
            ? TenantFeePayment::query()
                ->where('year', $year)
                ->where('month', $outstandingMonth)
                ->pluck('tenant_id')
            : collect();

        $outstanding = $outstandingMonth
            ? Tenant::query()
                ->where('status', 'active')
                ->where('payment_plan', 'monthly')
                ->whereNotNull('plan_amount')
                ->whereNotIn('id', $paidTenantIds)
                ->orderBy('business_name')
                ->get(['id', 'business_name', 'plan_amount'])
                ->map(fn (Tenant $tenant) => [
                    'tenant_id' => $tenant->id,
                    'business_name' => $tenant->business_name,
                    'plan_amount' => round((float) $tenant->plan_amount, 2),
                ])
                ->values()
            : collect();

        $setupOutstanding = Tenant::query()
            ->where('status', 'active')
            ->whereNotNull('setup_fee_amount')
            ->where('setup_fee_amount', '>', 0)
            ->withSum('setupFeePayments', 'amount')
            ->orderBy('business_name')
            ->get(['id', 'business_name', 'setup_fee_amount'])
            ->map(fn (Tenant $tenant) => $tenant->withSetupFeeTotals())
            ->filter(fn (Tenant $tenant) => (float) $tenant->setup_fee_balance > 0)
            ->map(fn (Tenant $tenant) => [
                'tenant_id' => $tenant->id,
                'business_name' => $tenant->business_name,
                'setup_fee_amount' => round((float) $tenant->setup_fee_amount, 2),
                'setup_fee_paid' => round((float) $tenant->setup_fee_paid, 2),
                'setup_fee_balance' => round((float) $tenant->setup_fee_balance, 2),
            ])
            ->values();

        $expected = round((float) Tenant::query()
            ->where('status', 'active')
            ->where('payment_plan', 'monthly')
            ->whereNotNull('plan_amount')
            ->sum('plan_amount'), 2);

        $ledger = $payments->map(fn (TenantFeePayment $payment) => [
            'id' => $payment->id,
            'kind' => 'monthly_fee',
            'tenant_id' => $payment->tenant_id,
            'business_name' => $payment->tenant?->business_name ?? 'Removed business',
            'year' => $payment->year,
            'month' => $payment->month,
            'period' => sprintf('%04d-%02d', $payment->year, $payment->month),
            'amount' => round((float) $payment->amount, 2),
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'notes' => $payment->notes,
        ])->concat($setupPayments->map(fn (TenantSetupFeePayment $payment) => [
            'id' => $payment->id,
            'kind' => 'setup_fee',
            'tenant_id' => $payment->tenant_id,
            'business_name' => $payment->tenant?->business_name ?? 'Removed business',
            'year' => (int) $payment->paid_at?->year,
            'month' => (int) $payment->paid_at?->month,
            'period' => $payment->paid_at?->format('Y-m'),
            'amount' => round((float) $payment->amount, 2),
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'notes' => $payment->notes,
        ]))->sortByDesc('paid_at')->values();

        return response()->json([
            'year' => $year,
            'month' => $month,
            'collected' => round((float) $payments->sum('amount') + (float) $setupPayments->sum('amount'), 2),
            'payment_count' => $payments->count() + $setupPayments->count(),
            'expected_monthly' => $expected,
            'outstanding_month' => $outstandingMonth,
            'outstanding_total' => round((float) $outstanding->sum('plan_amount'), 2),
            'outstanding' => $outstanding,
            'setup_outstanding_total' => round((float) $setupOutstanding->sum('setup_fee_balance'), 2),
            'setup_outstanding' => $setupOutstanding,
            'fee_collected' => round((float) $payments->sum('amount'), 2),
            'setup_collected' => round((float) $setupPayments->sum('amount'), 2),
            'months' => $monthRows,
            'tenants' => $byTenant,
            'payments' => $ledger,
        ]);
    }
}
