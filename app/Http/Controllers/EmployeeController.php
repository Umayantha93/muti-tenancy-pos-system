<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmployeeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return $this->moneyJson(Employee::query()
            ->with('defaultShift:id,name,start_time,end_time,paid_hours')
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
        return $this->moneyJson($employee->load(['attendance' => fn ($query) => $query->latest('date')->limit(31), 'payrolls' => fn ($query) => $query->latest('year')->latest('month')]));
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
        return $request->validate([
            'name' => [$employee ? 'sometimes' : 'required', 'string', 'max:255'],
            'nic' => [$employee ? 'sometimes' : 'required', 'string', 'max:20', Rule::unique('employees')->where('tenant_id', $request->user()->tenant_id)->ignore($employee)],
            'phone' => [$employee ? 'sometimes' : 'required', 'regex:/^[0-9+() -]{7,20}$/'],
            'position' => [$employee ? 'sometimes' : 'required', 'string', 'max:100'],
            'base_salary' => [$employee ? 'sometimes' : 'required', 'numeric', 'min:0'],
            'overtime_hourly_rate' => ['nullable', 'numeric', 'min:0'],
            'fingerprint_id' => [$employee ? 'sometimes' : 'required', 'string', 'max:100', Rule::unique('employees')->where('tenant_id', $request->user()->tenant_id)->ignore($employee)],
            'active' => ['sometimes', 'boolean'],
            'epf_enabled' => ['sometimes', 'boolean'],
            'paid_leave_days_per_year' => ['nullable', 'integer', 'min:0', 'max:366'],
            'default_shift_id' => ['nullable', Rule::exists('work_shifts', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'allowances' => ['nullable', 'array'],
            'allowances.*.name' => ['required_with:allowances', 'string', 'max:80'],
            'allowances.*.amount' => ['required_with:allowances', 'numeric', 'min:0'],
            'allowances.*.kind' => ['nullable', Rule::in(['fixed', 'attendance'])],
            'allowances.*.min_days' => ['nullable', 'integer', 'min:0', 'max:31'],
        ]);
    }
}
