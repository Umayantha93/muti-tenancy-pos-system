<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeePayment;
use App\Models\Payroll;
use App\Services\PayrollGenerator;
use App\Support\BranchQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        return $this->moneyJson(Payroll::with('employee')
            ->tap(fn ($query) => \App\Support\BranchQuery::constrain($query))
            ->when(isset($data['month']), fn ($query) => $query->where('month', $data['month']))
            ->when(isset($data['year']), fn ($query) => $query->where('year', $data['year']))
            ->when(isset($data['employee_id']), fn ($query) => $query->where('employee_id', $data['employee_id']))
            ->latest('year')->latest('month')->paginate(30));
    }

    public function show(Request $request, Payroll $payroll): JsonResponse
    {
        $payload = $payroll->load('employee')->toArray();
        $payload['tenant'] = $request->user()?->tenant;

        return $this->moneyJson($payload);
    }

    public function generate(Request $request, PayrollGenerator $generator): JsonResponse
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

        $result = $generator->generate(
            $request,
            (int) $data['month'],
            (int) $data['year'],
            $data['employee_id'] ?? null,
            $data['bonuses'] ?? [],
            $data['deductions'] ?? [],
        );

        $epfTotal = $result['payrolls']->sum(fn (Payroll $row) => (float) $row->epf_employee + (float) $row->epf_employer);
        $etfTotal = $result['payrolls']->sum(fn (Payroll $row) => (float) $row->etf_employer);

        return $this->moneyJson([
            'data' => $result['payrolls']->values(),
            'workdays' => $result['workdays'],
            'epf_payable' => round($epfTotal, 2),
            'etf_payable' => round($etfTotal, 2),
        ]);
    }

    public function dayWages(Request $request): JsonResponse
    {
        $date = $request->validate([
            'date' => ['nullable', 'date'],
        ])['date'] ?? now()->toDateString();

        $shopId = BranchQuery::idForRead();
        $employees = Employee::query()
            ->where('active', true)
            ->where('pay_type', 'daily')
            ->when($shopId, fn ($query) => $query->where('home_branch_id', $shopId))
            ->orderBy('name')
            ->get(['id', 'name', 'nic', 'daily_rate', 'position']);

        $paid = EmployeePayment::query()
            ->where('kind', EmployeePayment::KIND_DAILY_WAGE)
            ->whereDate('paid_on', $date)
            ->whereIn('employee_id', $employees->pluck('id'))
            ->get()
            ->keyBy('employee_id');

        return $this->moneyJson([
            'date' => $date,
            'employees' => $employees->map(function (Employee $employee) use ($paid) {
                $payment = $paid->get($employee->id);

                return [
                    'id' => $employee->id,
                    'name' => $employee->name,
                    'nic' => $employee->nic,
                    'position' => $employee->position,
                    'daily_rate' => $employee->daily_rate,
                    'paid' => $payment !== null,
                    'payment_id' => $payment?->id,
                    'amount' => $payment?->amount,
                ];
            })->values(),
        ]);
    }

    public function payDayWage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', Rule::exists('employees', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'paid_on' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
        ]);

        $employee = Employee::query()->findOrFail($data['employee_id']);
        abort_unless($employee->pay_type === 'daily', 422, 'This employee is paid monthly.');
        abort_if(
            EmployeePayment::query()
                ->where('employee_id', $employee->id)
                ->where('kind', EmployeePayment::KIND_DAILY_WAGE)
                ->whereDate('paid_on', $data['paid_on'])
                ->exists(),
            422,
            'This employee is already paid for that day.',
        );

        $payment = EmployeePayment::create([
            'employee_id' => $employee->id,
            'kind' => EmployeePayment::KIND_DAILY_WAGE,
            'amount' => $data['amount'],
            'paid_on' => $data['paid_on'],
            'created_by' => $request->user()->id,
        ]);

        return $this->moneyJson($payment->load('employee:id,name'), 201);
    }
}
