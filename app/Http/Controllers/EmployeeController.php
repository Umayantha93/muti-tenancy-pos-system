<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    public function options(): JsonResponse
    {
        return response()->json([
            'data' => Employee::query()
                ->where('active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'position']),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        return $this->moneyJson(Employee::query()
            ->with('defaultShift:id,name,start_time,end_time,paid_hours')
            ->with('homeBranch:id,name,code')
            ->when($request->boolean('active_only'), fn ($query) => $query->where('active', true))
            ->when($request->filled('search'), fn ($query) => $query->where('name', 'like', '%'.$request->string('search').'%'))
            ->orderBy('name')->paginate($request->integer('per_page', 20)));
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(Employee::create($this->validated($request)), 201);
    }

    public function show(Employee $employee): JsonResponse
    {
        return $this->moneyJson($employee->load([
            'attendance' => fn ($query) => $query->latest('date')->limit(31),
            'payrolls' => fn ($query) => $query->latest('year')->latest('month'),
            'bills' => fn ($query) => $query->with('vehicle:id,number_plate')->latest('bills.admission_date')->limit(50),
        ]));
    }

    public function update(Request $request, Employee $employee): JsonResponse
    {
        $employee->update($this->validated($request, $employee));
        return response()->json($employee->refresh());
    }

    public function destroy(Employee $employee): JsonResponse
    {
        $employee->update(['active' => false]);
        return response()->json(null, 204);
    }

    public function activate(Employee $employee): JsonResponse
    {
        $employee->update(['active' => true]);

        return response()->json($employee->refresh());
    }

    private function validated(Request $request, ?Employee $employee = null): array
    {
        if ($request->exists('amount')) {
            $payType = $request->input('pay_type', $employee?->pay_type ?? 'monthly');
            if ($payType === 'daily') {
                $request->merge([
                    'daily_rate' => $request->input('amount'),
                    'base_salary' => 0,
                ]);
            } else {
                $request->merge([
                    'base_salary' => $request->input('amount'),
                    'daily_rate' => null,
                ]);
            }
        }

        $data = $request->validate([
            'name' => [$employee ? 'sometimes' : 'required', 'string', 'max:255'],
            'nic' => [$employee ? 'sometimes' : 'required', 'string', 'max:20', Rule::unique('employees')->where('tenant_id', $request->user()->tenant_id)->ignore($employee)],
            'phone' => ['nullable', 'regex:/^[0-9+() -]{7,20}$/'],
            'position' => ['nullable', 'string', 'max:100'],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'base_salary' => ['nullable', 'numeric', 'min:0'],
            'pay_type' => ['nullable', Rule::in(['monthly', 'daily'])],
            'daily_rate' => ['nullable', 'numeric', 'gt:0', 'required_if:pay_type,daily'],
            'commission_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'overtime_hourly_rate' => ['nullable', 'numeric', 'min:0'],
            'fingerprint_id' => ['nullable', 'string', 'max:100', Rule::unique('employees')->where('tenant_id', $request->user()->tenant_id)->ignore($employee)],
            'active' => ['sometimes', 'boolean'],
            'epf_enabled' => ['sometimes', 'boolean'],
            'paid_leave_days_per_year' => ['nullable', 'integer', 'min:0', 'max:366'],
            'default_shift_id' => ['nullable', Rule::exists('work_shifts', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'home_branch_id' => [$employee ? 'sometimes' : 'nullable', Rule::exists('branches', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'allowances' => ['nullable', 'array'],
            'allowances.*.name' => ['required_with:allowances', 'string', 'max:80'],
            'allowances.*.amount' => ['required_with:allowances', 'numeric', 'min:0'],
            'allowances.*.kind' => ['nullable', Rule::in(['fixed', 'attendance'])],
            'allowances.*.min_days' => ['nullable', 'integer', 'min:0', 'max:31'],
        ]);

        unset($data['amount']);
        if (! isset($data['pay_type']) || $data['pay_type'] === null || $data['pay_type'] === '') {
            $data['pay_type'] = $employee?->pay_type ?? 'monthly';
        }
        if (array_key_exists('base_salary', $data) && $data['base_salary'] === null) {
            $data['base_salary'] = 0;
        }
        if ($data['pay_type'] !== 'daily' && ! array_key_exists('daily_rate', $data)) {
            $data['daily_rate'] = $employee?->daily_rate;
        }

        return $data;
    }
}
