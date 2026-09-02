<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Bill;
use App\Models\Employee;
use App\Models\Part;
use App\Models\Payroll;
use App\Models\Product;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ReportController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'employee_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->where('tenant_id', $request->user()->tenant_id)],
        ]);

        $from = Carbon::parse($data['from'] ?? now()->startOfMonth())->startOfDay();
        $to = Carbon::parse($data['to'] ?? now()->endOfMonth())->endOfDay();

        $bills = Bill::query()
            ->with('customer:id,name,phone')
            ->whereBetween('admission_date', [$from->toDateString(), $to->toDateString()])
            ->get();

        $salesByDay = $bills
            ->groupBy(fn (Bill $bill) => $bill->admission_date?->format('Y-m-d'))
            ->map(fn ($group, $day) => [
                'date' => $day,
                'billed' => round($group->sum(fn (Bill $bill) => (float) $bill->subtotal), 2),
                'paid' => round($group->sum(fn (Bill $bill) => (float) $bill->amount_paid), 2),
            ])
            ->values();

        $owing = Bill::query()
            ->with('customer:id,name,phone')
            ->whereIn('status', ['open', 'partially_paid', 'owe_in'])
            ->where('balance_due', '>', 0)
            ->orderByDesc('balance_due')
            ->limit(50)
            ->get(['id', 'bill_number', 'customer_id', 'status', 'balance_due', 'owe_in_due_date', 'admission_date']);

        $parts = Part::query()->get(['id', 'name', 'stock_qty', 'cost_price', 'price']);
        $products = Product::query()->get(['id', 'name', 'stock_qty', 'cost_price', 'price']);
        $stockItems = $parts->concat($products);
        $lowStock = $stockItems->filter(fn ($item) => (int) $item->stock_qty <= 5)->values();

        $payrollMonth = (int) $from->month;
        $payrollYear = (int) $from->year;
        $payrolls = Payroll::with('employee:id,name,position')
            ->where('month', $payrollMonth)
            ->where('year', $payrollYear)
            ->get();

        $attendanceRows = Attendance::query()
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('employee_id, sum(hours_worked) as hours, sum(overtime_hours) as overtime, count(*) as days')
            ->groupBy('employee_id')
            ->get();
        $staffById = Employee::query()
            ->whereIn('id', $attendanceRows->pluck('employee_id')->filter()->all())
            ->get(['id', 'name'])
            ->keyBy('id');
        $attendance = $attendanceRows->map(fn ($row) => [
            'employee_id' => $row->employee_id,
            'employee' => $staffById->get($row->employee_id),
            'hours' => round((float) $row->hours, 2),
            'overtime' => round((float) $row->overtime, 2),
            'days' => (int) $row->days,
        ])->values();

        $employees = Employee::query()
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'position']);

        $employeeJobs = null;
        if (! empty($data['employee_id'])) {
            $employee = Employee::query()->find($data['employee_id']);
            $jobs = Bill::query()
                ->with(['customer:id,name,phone', 'vehicle:id,number_plate,make,model'])
                ->whereHas('employees', fn ($query) => $query->where('employees.id', $data['employee_id']))
                ->whereBetween('admission_date', [$from->toDateString(), $to->toDateString()])
                ->orderByDesc('admission_date')
                ->orderByDesc('id')
                ->get(['id', 'bill_number', 'customer_id', 'vehicle_id', 'admission_date', 'status', 'job_kind', 'subtotal', 'amount_paid', 'balance_due']);

            $employeeJobs = [
                'employee' => $employee ? $employee->only(['id', 'name', 'position']) : null,
                'count' => $jobs->count(),
                'billed' => round($jobs->sum(fn (Bill $bill) => (float) $bill->subtotal), 2),
                'jobs' => $jobs,
            ];
        }

        return $this->moneyJson([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'sales' => [
                'billed' => round($bills->sum(fn (Bill $bill) => (float) $bill->subtotal), 2),
                'paid' => round($bills->sum(fn (Bill $bill) => (float) $bill->amount_paid), 2),
                'outstanding' => round($owing->sum(fn (Bill $bill) => (float) $bill->balance_due), 2),
                'count' => $bills->count(),
                'by_day' => $salesByDay,
            ],
            'stock' => [
                'on_hand_value' => round($stockItems->sum(fn ($item) => (float) $item->stock_qty * (float) ($item->cost_price ?? 0)), 2),
                'sku_count' => $stockItems->count(),
                'low_stock' => $lowStock->take(30)->map(fn ($item) => [
                    'id' => $item->id,
                    'name' => $item->name,
                    'stock_qty' => $item->stock_qty,
                ])->values(),
            ],
            'receivables' => $owing,
            'staff' => [
                'payroll_net' => round($payrolls->sum(fn (Payroll $row) => (float) $row->net_salary), 2),
                'payrolls' => $payrolls,
                'attendance' => $attendance,
            ],
            'employees' => $employees,
            'employee_jobs' => $employeeJobs,
        ]);
    }
}
