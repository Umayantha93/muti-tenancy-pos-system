<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Services\BillProfitAnalyzer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BillProfitController extends Controller
{
    public function index(Request $request, BillProfitAnalyzer $analyzer): JsonResponse
    {
        $data = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'job_kind' => ['nullable', Rule::in([Bill::JOB_KIND_SERVICE, Bill::JOB_KIND_REPAIR])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $from = $data['date_from'] ?? now()->subDays(29)->toDateString();
        $to = $data['date_to'] ?? now()->toDateString();
        $jobKind = $data['job_kind'] ?? null;

        $bills = Bill::query()
            ->with(['customer', 'vehicle', 'items.part', 'payments'])
            ->whereDate('admission_date', '>=', $from)
            ->whereDate('admission_date', '<=', $to)
            ->when($jobKind, fn ($query) => $query->where('job_kind', $jobKind))
            ->latest('admission_date')
            ->orderByDesc('id')
            ->paginate($data['per_page'] ?? 50);

        $summaries = collect($bills->items())->map(function (Bill $bill) use ($analyzer) {
            $summary = $analyzer->summarize($bill);

            return [
                'id' => $bill->id,
                'bill_number' => $bill->bill_number,
                'admission_date' => $bill->admission_date?->toDateString(),
                'status' => $bill->status,
                'owe_in_due_date' => $bill->owe_in_due_date?->toDateString(),
                'customer' => $bill->customer,
                'vehicle' => $bill->vehicle,
                'amount_paid' => $bill->amount_paid,
                'balance_due' => $bill->balance_due,
                'subtotal' => $bill->subtotal,
                ...$summary,
            ];
        });

        $allForPeriod = Bill::query()
            ->with(['items.part'])
            ->whereDate('admission_date', '>=', $from)
            ->whereDate('admission_date', '<=', $to)
            ->when($jobKind, fn ($query) => $query->where('job_kind', $jobKind))
            ->get();

        $totals = $allForPeriod->reduce(function (array $carry, Bill $bill) use ($analyzer) {
            $summary = $analyzer->summarize($bill);
            $kind = $summary['job_kind'] === Bill::JOB_KIND_SERVICE ? 'service' : 'repair';
            $carry['total_revenue'] += $summary['revenue'];
            $carry['total_cogs'] += $summary['cogs'];
            $carry['gross_profit'] += $summary['profit'];
            $carry["{$kind}_revenue"] += $summary['revenue'];
            $carry["{$kind}_cogs"] += $summary['cogs'];
            $carry["{$kind}_gross_profit"] += $summary['profit'];
            $carry["{$kind}_count"]++;
            if ($summary['billing_type'] === 'credit') {
                $carry['credit_count']++;
                $carry['credit_generated'] += $summary['revenue'];
                $carry['credit_collected'] += (float) $bill->amount_paid;
                $carry['credit_pending'] += (float) $bill->balance_due;
            }

            return $carry;
        }, [
            'total_revenue' => 0.0,
            'total_cogs' => 0.0,
            'gross_profit' => 0.0,
            'service_revenue' => 0.0,
            'service_cogs' => 0.0,
            'service_gross_profit' => 0.0,
            'service_count' => 0,
            'repair_revenue' => 0.0,
            'repair_cogs' => 0.0,
            'repair_gross_profit' => 0.0,
            'repair_count' => 0,
            'credit_count' => 0,
            'credit_generated' => 0.0,
            'credit_collected' => 0.0,
            'credit_pending' => 0.0,
        ]);

        $totals['total_revenue'] = round($totals['total_revenue'], 2);
        $totals['total_cogs'] = round($totals['total_cogs'], 2);
        $totals['gross_profit'] = round($totals['gross_profit'], 2);
        $totals['service_revenue'] = round($totals['service_revenue'], 2);
        $totals['service_cogs'] = round($totals['service_cogs'], 2);
        $totals['service_gross_profit'] = round($totals['service_gross_profit'], 2);
        $totals['repair_revenue'] = round($totals['repair_revenue'], 2);
        $totals['repair_cogs'] = round($totals['repair_cogs'], 2);
        $totals['repair_gross_profit'] = round($totals['repair_gross_profit'], 2);
        $totals['margin'] = $totals['total_revenue'] > 0
            ? round(($totals['gross_profit'] / $totals['total_revenue']) * 100, 1)
            : 0.0;
        $totals['service_margin'] = $totals['service_revenue'] > 0
            ? round(($totals['service_gross_profit'] / $totals['service_revenue']) * 100, 1)
            : 0.0;
        $totals['repair_margin'] = $totals['repair_revenue'] > 0
            ? round(($totals['repair_gross_profit'] / $totals['repair_revenue']) * 100, 1)
            : 0.0;
        $totals['credit_generated'] = round($totals['credit_generated'], 2);
        $totals['credit_collected'] = round($totals['credit_collected'], 2);
        $totals['credit_pending'] = round($totals['credit_pending'], 2);

        $bills->setCollection($summaries);

        return $this->moneyJson([
            'period' => ['date_from' => $from, 'date_to' => $to],
            'job_kind' => $jobKind,
            ...$totals,
            'bills' => $bills->toArray(),
        ]);
    }

    public function show(Bill $bill, BillProfitAnalyzer $analyzer): JsonResponse
    {
        $summary = $analyzer->summarize($bill);

        return $this->moneyJson([
            'id' => $bill->id,
            'bill_number' => $bill->bill_number,
            'admission_date' => $bill->admission_date?->toDateString(),
            'status' => $bill->status,
            'owe_in_due_date' => $bill->owe_in_due_date?->toDateString(),
            'closed_at' => $bill->closed_at,
            'customer' => $bill->customer,
            'vehicle' => $bill->vehicle,
            'payments' => $bill->payments,
            'amount_paid' => $bill->amount_paid,
            'balance_due' => $bill->balance_due,
            'subtotal' => $bill->subtotal,
            'total_deductions' => $bill->total_deductions,
            ...$summary,
        ]);
    }
}
