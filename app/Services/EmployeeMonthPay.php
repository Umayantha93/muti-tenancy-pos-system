<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeePayMonth;
use App\Models\Payroll;
use App\Services\Commission\LaborExcessCommission;

class EmployeeMonthPay
{
    /**
     * @param  list<int>  $employeeIds
     * @return array<int, array{salary: float, ready: bool}>
     */
    public static function forEmployees(array $employeeIds, int $year, int $month): array
    {
        $ids = collect($employeeIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $employees = Employee::query()->whereIn('id', $ids)->get()->keyBy('id');
        $sheets = EmployeePayMonth::query()
            ->whereIn('employee_id', $ids)
            ->where('year', $year)
            ->where('month', $month)
            ->get()
            ->keyBy('employee_id');

        $pay = [];
        foreach ($ids as $id) {
            $employee = $employees->get($id);
            if (! $employee) {
                $pay[$id] = ['salary' => 0.0, 'ready' => true];

                continue;
            }
            $full = self::calculate($employee, $sheets->get($id));
            $pay[$id] = [
                'salary' => $full['wage'],
                'ready' => $full['ready'],
            ];
        }

        return $pay;
    }

    /**
     * Wage is the pay from dates only. Salary is the attendance month total,
     * after additions and deductions. Commission compares the wage.
     *
     * @return array{wage: float, salary: float, ready: bool}
     */
    public static function calculate(Employee $employee, ?EmployeePayMonth $sheet): array
    {
        $daily = $employee->pay_type === 'daily';
        $ready = ! $daily || $sheet !== null;
        $additions = self::total($sheet?->additions);
        $deductions = self::total($sheet?->deductions);

        if ($daily) {
            $units = (float) ($sheet->days ?? 0) + ((float) ($sheet->half_days ?? 0) * 0.5);
            $wage = round($units * (float) $employee->daily_rate, 2);
        } else {
            $wage = round((float) $employee->base_salary, 2);
        }

        return [
            'wage' => $wage,
            'salary' => round(max(0, $wage + $additions - $deductions), 2),
            'ready' => $ready,
        ];
    }

    /**
     * @param  list<array{name?: string, amount?: float|int|string}>|null  $lines
     */
    public static function total(?array $lines): float
    {
        return round((float) collect($lines ?? [])->sum(fn (array $line) => (float) ($line['amount'] ?? 0)), 2);
    }

    /**
     * @param  list<array{name?: string, amount?: float|int|string}>|null  $lines
     * @return list<array{name: string, amount: float}>
     */
    public static function namedLines(?array $lines): array
    {
        return collect($lines ?? [])
            ->map(fn (array $line) => [
                'name' => trim((string) ($line['name'] ?? '')),
                'amount' => round((float) ($line['amount'] ?? 0), 2),
            ])
            ->filter(fn (array $line) => $line['name'] !== '' && $line['amount'] > 0)
            ->values()
            ->all();
    }

    /**
     * Daily staff are paid from the attendance month sheet. Write that total
     * onto the payroll row for the same month so it shows with everyone else.
     */
    public static function postPayroll(Employee $employee, int $year, int $month, ?EmployeePayMonth $sheet, int $generatedBy): Payroll
    {
        $pay = self::calculate($employee, $sheet);
        $days = (int) ($sheet->days ?? 0);
        $additionLines = self::namedLines($sheet?->additions);
        $deductionLines = self::namedLines($sheet?->deductions);
        $additions = self::total($additionLines);
        $deductions = self::total($deductionLines);
        $commission = round((float) (app(LaborExcessCommission::class)->days($employee, $year, $month)['commission'] ?? 0), 2);
        $gross = round($pay['wage'] + $additions + $commission, 2);

        $payroll = Payroll::query()->firstOrNew([
            'employee_id' => $employee->id,
            'year' => $year,
            'month' => $month,
        ]);
        $payroll->fill([
            'days_present' => $days,
            'days_absent' => 0,
            'hours_worked' => 0,
            'overtime_hours' => 0,
            'base_salary' => $pay['wage'],
            'overtime_pay' => 0,
            'allowances_total' => $additions,
            'addition_lines' => $additionLines,
            'target_incentive' => 0,
            'gross_pay' => $gross,
            'epf_employee' => 0,
            'epf_employer' => 0,
            'etf_employer' => 0,
            'unpaid_leave_days' => 0,
            'bonus' => 0,
            'commission' => $commission,
            'deductions' => $deductions,
            'deduction_lines' => $deductionLines,
            'net_salary' => round(max(0, $gross - $deductions), 2),
            'generated_at' => now(),
            'generated_by' => $generatedBy,
            'branch_id' => $employee->home_branch_id,
        ]);
        if (! $payroll->tenant_id) {
            $payroll->tenant_id = $employee->tenant_id;
        }
        $payroll->save();

        return $payroll;
    }
}
