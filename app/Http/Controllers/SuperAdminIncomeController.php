<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\TenantFeePayment;
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

        $monthRows = collect(range(1, 12))->map(function (int $rowMonth) use ($payments, $year) {
            $rows = $payments->where('month', $rowMonth);

            return [
                'month' => $rowMonth,
                'period' => sprintf('%04d-%02d', $year, $rowMonth),
                'label' => now()->setMonth($rowMonth)->format('M'),
                'collected' => round((float) $rows->sum('amount'), 2),
                'count' => $rows->count(),
            ];
        })->values();

        $byTenant = $payments
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

        $expected = round((float) Tenant::query()
            ->where('status', 'active')
            ->where('payment_plan', 'monthly')
            ->whereNotNull('plan_amount')
            ->sum('plan_amount'), 2);

        return response()->json([
            'year' => $year,
            'month' => $month,
            'collected' => round((float) $payments->sum('amount'), 2),
            'payment_count' => $payments->count(),
            'expected_monthly' => $expected,
            'outstanding_month' => $outstandingMonth,
            'outstanding_total' => round((float) $outstanding->sum('plan_amount'), 2),
            'outstanding' => $outstanding,
            'months' => $monthRows,
            'tenants' => $byTenant,
            'payments' => $payments->map(fn (TenantFeePayment $payment) => [
                'id' => $payment->id,
                'tenant_id' => $payment->tenant_id,
                'business_name' => $payment->tenant?->business_name ?? 'Removed business',
                'year' => $payment->year,
                'month' => $payment->month,
                'period' => sprintf('%04d-%02d', $payment->year, $payment->month),
                'amount' => round((float) $payment->amount, 2),
                'paid_at' => $payment->paid_at?->toIso8601String(),
                'notes' => $payment->notes,
            ])->values(),
        ]);
    }
}
