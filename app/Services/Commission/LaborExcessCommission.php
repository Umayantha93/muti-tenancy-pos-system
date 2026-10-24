<?php

namespace App\Services\Commission;

use App\Models\Bill;
use App\Models\BillItem;
use App\Models\BillPayment;
use App\Models\Employee;
use App\Services\EmployeeMonthPay;
use App\Support\BranchQuery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Paid labor is compared with the monthly salary.
 * Repair labor counts in full. Service labor counts at half.
 * The employee receives 10% of the amount above salary.
 * Labor at or below salary pays no commission.
 */
class LaborExcessCommission implements CommissionScheme
{
    public const EXCESS_PERCENT = 10;

    public const SERVICE_SHARE = 50;

    public const REPAIR_SHARE = 100;

    public function key(): string
    {
        return CommissionSchemes::LABOR_EXCESS;
    }

    public function label(): string
    {
        return 'Labor above salary';
    }

    public function summary(): string
    {
        return 'Service labor counts at '.self::SERVICE_SHARE.'%. Repair labor counts at '.self::REPAIR_SHARE.'%. Monthly pay uses the amount as the salary. Daily pay uses the days and half days entered on Attendance, times the amount, plus additions and minus deductions. If counted labor is above that salary, the employee receives '.self::EXCESS_PERCENT.'% of the difference. If it is not above the salary, there is no commission. Only paid jobs are included.';
    }

    public function rules(): array
    {
        return [
            'excess_percent' => self::EXCESS_PERCENT,
            'service_share_percent' => self::SERVICE_SHARE,
            'repair_share_percent' => self::REPAIR_SHARE,
        ];
    }

    public function supportsJobDeduction(): bool
    {
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public function month(int $year, int $month): array
    {
        $lines = $this->lines($year, $month);
        $pay = EmployeeMonthPay::forEmployees($lines->pluck('employee_id')->all(), $year, $month);
        $employees = $lines
            ->groupBy('employee_id')
            ->map(fn (Collection $rows) => $this->summarize($rows, $pay))
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return [
            'type' => $this->key(),
            'label' => $this->label(),
            'year' => $year,
            'month' => $month,
            'rules' => $this->rules(),
            'commission_total' => round((float) $employees->sum('commission'), 2),
            'employees' => $employees->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function days(Employee $employee, int $year, int $month): array
    {
        $rows = $this->lines($year, $month)
            ->where('employee_id', $employee->id)
            ->values();
        $pay = EmployeeMonthPay::forEmployees([$employee->id], $year, $month);
        $summary = $rows->isEmpty()
            ? $this->emptySummary($employee, $pay)
            : $this->summarize($rows, $pay);

        $days = $rows
            ->groupBy('paid_on')
            ->map(function (Collection $dayRows, string $date) {
                $jobs = $dayRows
                    ->groupBy('bill_id')
                    ->map(function (Collection $jobRows) {
                        $first = $jobRows->first();

                        return [
                            'bill_id' => $first['bill_id'],
                            'bill_number' => $first['bill_number'],
                            'number_plate' => $first['number_plate'],
                            'job_kind' => $first['job_kind'],
                            'paid_on' => $first['paid_on'],
                            'team' => $first['team'],
                            'counted' => round((float) $jobRows->sum('counted'), 2),
                            'lines' => $jobRows->map(fn (array $line) => [
                                'description' => $line['description'],
                                'section' => $line['section'],
                                'labor_amount' => $line['labor_amount'],
                                'share_percent' => $line['share_percent'],
                                'counted' => $line['counted'],
                            ])->values()->all(),
                        ];
                    })
                    ->sortBy('bill_number', SORT_NATURAL | SORT_FLAG_CASE)
                    ->values();

                return [
                    'date' => $date,
                    'labor_pool' => round((float) $dayRows->sum('counted'), 2),
                    'jobs' => $jobs->all(),
                ];
            })
            ->sortKeys()
            ->values();

        return [
            'type' => $this->key(),
            'label' => $this->label(),
            'year' => $year,
            'month' => $month,
            'rules' => $this->rules(),
            ...$summary,
            'days' => $days->all(),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function lines(int $year, int $month): Collection
    {
        $start = Carbon::create($year, $month, 1)->startOfMonth()->startOfDay();
        $end = $start->copy()->endOfMonth()->endOfDay();

        $bills = BranchQuery::constrain(Bill::query())
            ->whereIn('status', ['paid', 'closed'])
            ->where('is_free', false)
            ->where('balance_due', '<=', 0)
            ->where('amount_paid', '>', 0)
            ->whereHas('payments', function ($query) use ($start, $end) {
                $query->countingTowardPaid()->whereBetween('paid_at', [$start, $end]);
            })
            ->with([
                'vehicle:id,number_plate',
                'payments',
                'items.employee:id,name,position,base_salary,daily_rate,pay_type',
                'employees:id,name,position,base_salary,daily_rate,pay_type',
            ])
            ->get()
            ->filter(function (Bill $bill) use ($start, $end) {
                $paidOn = $this->settledOn($bill);

                return $paidOn && $paidOn->between($start, $end);
            });

        $lines = collect();
        foreach ($bills as $bill) {
            $paidOn = $this->settledOn($bill)?->toDateString();
            $team = $bill->employees
                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                ->pluck('name')
                ->values()
                ->all();
            foreach ($bill->items as $item) {
                $bucket = $this->bucket($bill, $item);
                if ($bucket === null || $paidOn === null) {
                    continue;
                }
                $recipients = $this->recipients($bill, $item);
                if ($recipients === []) {
                    continue;
                }
                $full = round((float) $item->line_total * $bucket['share_percent'] / 100, 2);
                if ($full <= 0) {
                    continue;
                }
                $count = count($recipients);
                $running = 0.0;
                foreach ($recipients as $index => $employee) {
                    $counted = $index === $count - 1
                        ? round($full - $running, 2)
                        : round($full / $count, 2);
                    $running = round($running + $counted, 2);
                    if ($counted <= 0) {
                        continue;
                    }
                    $lines->push([
                        'employee_id' => $employee->id,
                        'name' => $employee->name,
                        'position' => $employee->position,
                        'bill_id' => $bill->id,
                        'bill_number' => $bill->bill_number,
                        'number_plate' => $bill->vehicle?->number_plate,
                        'job_kind' => $bill->job_kind,
                        'paid_on' => $paidOn,
                        'team' => $team,
                        'description' => $item->description,
                        'section' => $bucket['section'],
                        'labor_amount' => round((float) $item->line_total, 2),
                        'share_percent' => $bucket['share_percent'],
                        'counted' => $counted,
                        'pay_type' => $employee->pay_type,
                    ]);
                }
            }
        }

        return $lines;
    }

    /**
     * @return array{section: string, share_percent: int}|null
     */
    private function bucket(Bill $bill, BillItem $item): ?array
    {
        if (! $item->earnsCommission()) {
            return null;
        }
        if ($bill->job_kind === Bill::JOB_KIND_SERVICE) {
            return ['section' => 'service', 'share_percent' => self::SERVICE_SHARE];
        }
        if ($bill->job_kind === Bill::JOB_KIND_REPAIR || $bill->job_kind === Bill::JOB_KIND_PARTS_SALE) {
            return ['section' => 'repair', 'share_percent' => self::REPAIR_SHARE];
        }

        $section = $item->section === 'service' ? 'service' : 'repair';

        return [
            'section' => $section,
            'share_percent' => $section === 'service' ? self::SERVICE_SHARE : self::REPAIR_SHARE,
        ];
    }

    /**
     * A line with a technician counts for that person. A line with none is shared across the team.
     *
     * @return list<Employee>
     */
    private function recipients(Bill $bill, BillItem $item): array
    {
        if ($item->employee_id) {
            $employee = $item->employee;

            return $employee ? [$employee] : [];
        }

        return $bill->employees
            ->sortBy('id')
            ->values()
            ->all();
    }

    private function settledOn(Bill $bill): ?Carbon
    {
        $payment = $bill->payments
            ->filter(fn (BillPayment $payment) => $payment->countsTowardPaid() && $payment->paid_at)
            ->sortByDesc(fn (BillPayment $payment) => $payment->paid_at->getTimestamp())
            ->first();

        return $payment?->paid_at;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<int, array{salary: float, ready: bool}>  $pay
     * @return array<string, mixed>
     */
    private function summarize(Collection $rows, array $pay): array
    {
        $first = $rows->first();
        $repair = round((float) $rows->where('section', 'repair')->sum('counted'), 2);
        $service = round((float) $rows->where('section', 'service')->sum('counted'), 2);
        $pool = round($repair + $service, 2);
        $person = $pay[(int) $first['employee_id']] ?? ['salary' => 0.0, 'ready' => true];
        $salary = round((float) $person['salary'], 2);
        $ready = (bool) $person['ready'];
        $above = round($pool - $salary, 2);
        $hasCommission = $ready && $above > 0;

        return [
            'id' => $first['employee_id'],
            'name' => $first['name'],
            'position' => $first['position'],
            'pay_type' => $first['pay_type'] ?? 'monthly',
            'salary' => $salary,
            'salary_ready' => $ready,
            'repair_counted' => $repair,
            'service_counted' => $service,
            'labor_pool' => $pool,
            'excess' => $hasCommission ? $above : 0.0,
            'commission' => $hasCommission ? round($above * self::EXCESS_PERCENT / 100, 2) : 0.0,
            'has_commission' => $hasCommission,
            'job_count' => $rows->pluck('bill_id')->unique()->count(),
        ];
    }

    /**
     * @param  array<int, array{salary: float, ready: bool}>  $pay
     * @return array<string, mixed>
     */
    private function emptySummary(Employee $employee, array $pay): array
    {
        $person = $pay[$employee->id] ?? ['salary' => 0.0, 'ready' => $employee->pay_type !== 'daily'];

        return [
            'id' => $employee->id,
            'name' => $employee->name,
            'position' => $employee->position,
            'pay_type' => $employee->pay_type ?: 'monthly',
            'salary' => round((float) $person['salary'], 2),
            'salary_ready' => (bool) $person['ready'],
            'repair_counted' => 0.0,
            'service_counted' => 0.0,
            'labor_pool' => 0.0,
            'excess' => 0.0,
            'commission' => 0.0,
            'has_commission' => false,
            'job_count' => 0,
        ];
    }
}
