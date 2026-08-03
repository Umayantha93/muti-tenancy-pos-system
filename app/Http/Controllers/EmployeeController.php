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
        return response()->json(Employee::query()
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
        return response()->json($employee->load(['attendance' => fn ($query) => $query->latest('date')->limit(31), 'payrolls' => fn ($query) => $query->latest('year')->latest('month')]));
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
        ]);
    }
}
