<?php

namespace App\Http\Controllers;

use App\Services\PayrollGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\Payroll;
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
}
