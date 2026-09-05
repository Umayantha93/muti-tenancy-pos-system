<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\EmployeeTarget;
use App\Models\Payroll;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PayrollGenerator
{
    public const EPF_EMPLOYEE_RATE = 0.08;

    public const EPF_EMPLOYER_RATE = 0.12;

    public const ETF_EMPLOYER_RATE = 0.03;

    /**
     * @param  array<int, float>  $bonuses
     * @param  array<int, float>  $deductions
     * @return array{payrolls: \Illuminate\Support\Collection<int, Payroll>, workdays: int}
     */
    public function generate(Request $request, int $month, int $year, ?int $employeeId = null, array $bonuses = [], array $deductions = []): array
    {
        $employees = Employee::where('active', true)
            ->when($employeeId, fn ($query) => $query->whereKey($employeeId))
            ->with([
                'attendance' => fn ($query) => $query->whereYear('date', $year)->whereMonth('date', $month),
                'defaultShift',
            ])
            ->get();

        $workdays = collect(CarbonPeriod::create(
            Carbon::create($year, $month)->startOfMonth(),
            Carbon::create($year, $month)->endOfMonth(),
        ))->reject(fn (Carbon $date) => $date->isWeekend())->count();

        $payrolls = DB::transaction(function () use ($employees, $month, $year, $workdays, $request, $bonuses, $deductions) {
            return $employees->map(function (Employee $employee) use ($month, $year, $workdays, $request, $bonuses, $deductions) {
                $daysPresent = $employee->attendance->count();
                $hoursWorked = (float) $employee->attendance->sum('hours_worked');
                $overtimeHours = (float) $employee->attendance->sum('overtime_hours');
                $overtimePay = round($overtimeHours * (float) $employee->overtime_hourly_rate, 2);
                $allowances = $this->allowancesFor($employee, $daysPresent);
                $unpaidLeaveDays = $this->unpaidLeaveDays($employee, $month, $year);
                $dailyRate = $workdays > 0 ? ((float) $employee->base_salary / $workdays) : 0;
                $unpaidDeduction = round($dailyRate * $unpaidLeaveDays, 2);
                $bonus = (float) ($bonuses[$employee->id] ?? 0);
                $extraDeductions = (float) ($deductions[$employee->id] ?? 0);
                $incentive = $this->targetIncentive($employee, $month, $year);
                $gross = round((float) $employee->base_salary + $overtimePay + $allowances + $bonus + $incentive, 2);
                $epfEmployee = $employee->epf_enabled ? round($gross * self::EPF_EMPLOYEE_RATE, 2) : 0.0;
                $epfEmployer = $employee->epf_enabled ? round($gross * self::EPF_EMPLOYER_RATE, 2) : 0.0;
                $etfEmployer = $employee->epf_enabled ? round($gross * self::ETF_EMPLOYER_RATE, 2) : 0.0;
                $net = max(0, $gross - $epfEmployee - $unpaidDeduction - $extraDeductions);

                return Payroll::updateOrCreate(
                    ['employee_id' => $employee->id, 'month' => $month, 'year' => $year],
                    [
                        'days_present' => $daysPresent,
                        'days_absent' => max(0, $workdays - $daysPresent),
                        'hours_worked' => $hoursWorked,
                        'overtime_hours' => $overtimeHours,
                        'base_salary' => $employee->base_salary,
                        'overtime_pay' => $overtimePay,
                        'allowances_total' => $allowances,
                        'target_incentive' => $incentive,
                        'gross_pay' => $gross,
                        'epf_employee' => $epfEmployee,
                        'epf_employer' => $epfEmployer,
                        'etf_employer' => $etfEmployer,
                        'unpaid_leave_days' => $unpaidLeaveDays,
                        'bonus' => $bonus,
                        'deductions' => round($unpaidDeduction + $extraDeductions, 2),
                        'net_salary' => $net,
                        'generated_at' => now(),
                        'generated_by' => $request->user()->id,
                        'branch_id' => $employee->home_branch_id,
                    ],
                )->load('employee');
            });
        });

        return ['payrolls' => $payrolls, 'workdays' => $workdays];
    }

    private function allowancesFor(Employee $employee, int $daysPresent): float
    {
        $total = 0.0;
        foreach ($employee->allowances ?? [] as $allowance) {
            $amount = (float) ($allowance['amount'] ?? 0);
            $kind = $allowance['kind'] ?? 'fixed';
            if ($kind === 'attendance') {
                $minDays = (int) ($allowance['min_days'] ?? 0);
                if ($daysPresent < $minDays) {
                    continue;
                }
            }
            $total += $amount;
        }

        return round($total, 2);
    }

    private function unpaidLeaveDays(Employee $employee, int $month, int $year): int
    {
        $start = Carbon::create($year, $month)->startOfMonth()->toDateString();
        $end = Carbon::create($year, $month)->endOfMonth()->toDateString();

        return (int) EmployeeLeave::query()
            ->where('employee_id', $employee->id)
            ->where('type', 'unpaid')
            ->where('status', EmployeeLeave::STATUS_APPROVED)
            ->whereDate('start_date', '<=', $end)
            ->whereDate('end_date', '>=', $start)
            ->get()
            ->sum(function (EmployeeLeave $leave) use ($start, $end) {
                $from = max($leave->start_date->toDateString(), $start);
                $to = min($leave->end_date->toDateString(), $end);

                return Carbon::parse($from)->diffInDays(Carbon::parse($to)) + 1;
            });
    }

    private function targetIncentive(Employee $employee, int $month, int $year): float
    {
        $start = Carbon::create($year, $month)->startOfMonth()->toDateString();
        $end = Carbon::create($year, $month)->endOfMonth()->toDateString();

        $personal = (float) EmployeeTarget::query()
            ->where('scope', EmployeeTarget::SCOPE_EMPLOYEE)
            ->where('employee_id', $employee->id)
            ->whereDate('starts_on', '<=', $end)
            ->whereDate('ends_on', '>=', $start)
            ->get()
            ->sum(function (EmployeeTarget $target) {
                if ((float) $target->progress_amount >= (float) $target->amount) {
                    return (float) $target->incentive_amount;
                }

                return 0;
            });

        $team = (float) EmployeeTarget::query()
            ->where('scope', EmployeeTarget::SCOPE_TEAM)
            ->whereNull('employee_id')
            ->whereDate('starts_on', '<=', $end)
            ->whereDate('ends_on', '>=', $start)
            ->get()
            ->sum(function (EmployeeTarget $target) {
                if ((float) $target->progress_amount >= (float) $target->amount) {
                    return (float) $target->incentive_amount;
                }

                return 0;
            });

        return $personal + $team;
    }
}
