<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Payroll;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PayrollController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'month' => ['nullable', 'integer', 'between:1,12'],
            'year' => ['nullable', 'integer', 'between:2020,2100'],
            'employee_id' => ['nullable', Rule::exists('employees', 'id')->where('tenant_id', $request->user()->tenant_id)],
        ]);

        return response()->json(Payroll::with('employee')
            ->when(isset($data['month']), fn ($query) => $query->where('month', $data['month']))
            ->when(isset($data['year']), fn ($query) => $query->where('year', $data['year']))
            ->when(isset($data['employee_id']), fn ($query) => $query->where('employee_id', $data['employee_id']))
            ->latest('year')->latest('month')->paginate(30));
    }

    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'month' => ['required', 'integer', 'between:1,12'],
            'year' => ['required', 'integer', 'between:2020,2100'],
            'employee_id' => ['nullable', Rule::exists('employees', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'bonuses' => ['nullable', 'array'],
            'bonuses.*' => ['numeric', 'min:0'],
            'deductions' => ['nullable', 'array'],
            'deductions.*' => ['numeric', 'min:0'],
        ]);

        $employees = Employee::where('active', true)
            ->when(isset($data['employee_id']), fn ($query) => $query->whereKey($data['employee_id']))
            ->with(['attendance' => fn ($query) => $query
                ->whereYear('date', $data['year'])->whereMonth('date', $data['month'])])
            ->get();

        $workdays = collect(CarbonPeriod::create(
            Carbon::create($data['year'], $data['month'])->startOfMonth(),
            Carbon::create($data['year'], $data['month'])->endOfMonth(),
        ))->reject(fn (Carbon $date) => $date->isWeekend())->count();

        $payrolls = DB::transaction(function () use ($employees, $data, $workdays, $request) {
            return $employees->map(function (Employee $employee) use ($data, $workdays, $request) {
                $daysPresent = $employee->attendance->count();
                $hoursWorked = (float) $employee->attendance->sum('hours_worked');
                $overtimeHours = (float) $employee->attendance->sum('overtime_hours');
                $overtimePay = round($overtimeHours * (float) $employee->overtime_hourly_rate, 2);
                $bonus = (float) ($data['bonuses'][$employee->id] ?? 0);
                $deductions = (float) ($data['deductions'][$employee->id] ?? 0);

                return Payroll::updateOrCreate(
                    ['employee_id' => $employee->id, 'month' => $data['month'], 'year' => $data['year']],
                    [
                        'days_present' => $daysPresent,
                        'days_absent' => max(0, $workdays - $daysPresent),
                        'hours_worked' => $hoursWorked,
                        'overtime_hours' => $overtimeHours,
                        'base_salary' => $employee->base_salary,
                        'overtime_pay' => $overtimePay,
                        'bonus' => $bonus,
                        'deductions' => $deductions,
                        'net_salary' => max(0, (float) $employee->base_salary + $overtimePay + $bonus - $deductions),
                        'generated_at' => now(),
                        'generated_by' => $request->user()->id,
                    ],
                )->load('employee');
            });
        });

        return response()->json(['data' => $payrolls, 'workdays' => $workdays]);
    }
}
