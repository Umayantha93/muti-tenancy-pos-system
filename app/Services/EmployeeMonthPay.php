<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeePayMonth;

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
            $pay[$id] = $employee
                ? self::calculate($employee, $sheets->get($id))
                : ['salary' => 0.0, 'ready' => true];
        }

        return $pay;
    }

    /**
     * @return array{salary: float, ready: bool}
     */
    public static function calculate(Employee $employee, ?EmployeePayMonth $sheet): array
    {
        $daily = $employee->pay_type === 'daily';
        $ready = ! $daily || $sheet !== null;
        $additions = self::total($sheet?->additions);
        $deductions = self::total($sheet?->deductions);

        if ($daily) {
            $units = (float) ($sheet->days ?? 0) + ((float) ($sheet->half_days ?? 0) * 0.5);
            $earned = round($units * (float) $employee->daily_rate, 2);
        } else {
            $earned = round((float) $employee->base_salary, 2);
        }

        return [
            'salary' => round(max(0, $earned + $additions - $deductions), 2),
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
}
