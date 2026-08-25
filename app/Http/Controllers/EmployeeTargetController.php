<?php

namespace App\Http\Controllers;

use App\Models\EmployeeTarget;
use App\Models\EmployeeTargetProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EmployeeTargetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['nullable', Rule::exists('employees', 'id')->where('tenant_id', $request->user()->tenant_id)],
        ]);

        return $this->moneyJson(EmployeeTarget::with(['employee:id,name,position', 'progressLogs.employee:id,name'])
            ->when(isset($data['employee_id']), fn ($query) => $query->where(function ($nested) use ($data) {
                $nested->where('employee_id', $data['employee_id'])
                    ->orWhere('scope', EmployeeTarget::SCOPE_TEAM);
            }))
            ->latest('starts_on')
            ->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope' => ['nullable', Rule::in([EmployeeTarget::SCOPE_EMPLOYEE, EmployeeTarget::SCOPE_TEAM])],
            'employee_id' => ['nullable', Rule::exists('employees', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'kind' => ['nullable', Rule::in(['sales', 'pieces'])],
            'amount' => ['required', 'numeric', 'gt:0'],
            'progress_amount' => ['nullable', 'numeric', 'min:0'],
            'incentive_amount' => ['nullable', 'numeric', 'min:0'],
        ]);
        $data['scope'] = $data['scope'] ?? EmployeeTarget::SCOPE_EMPLOYEE;
        if ($data['scope'] === EmployeeTarget::SCOPE_TEAM) {
            $data['employee_id'] = null;
        } else {
            abort_unless(! empty($data['employee_id']), 422, 'Choose an employee for a personal target.');
        }
        $data['kind'] = $data['kind'] ?? 'sales';
        $data['progress_amount'] = $data['progress_amount'] ?? 0;
        $data['incentive_amount'] = $data['incentive_amount'] ?? 0;

        return $this->moneyJson(EmployeeTarget::create($data)->load('employee'), 201);
    }

    public function update(Request $request, EmployeeTarget $target): JsonResponse
    {
        $data = $request->validate([
            'starts_on' => ['sometimes', 'date'],
            'ends_on' => ['sometimes', 'date'],
            'kind' => ['sometimes', Rule::in(['sales', 'pieces'])],
            'amount' => ['sometimes', 'numeric', 'gt:0'],
            'progress_amount' => ['sometimes', 'numeric', 'min:0'],
            'incentive_amount' => ['sometimes', 'numeric', 'min:0'],
        ]);
        $target->update($data);

        return $this->moneyJson($target->refresh()->load(['employee', 'progressLogs.employee:id,name']));
    }

    public function logProgress(Request $request, EmployeeTarget $target): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', Rule::exists('employees', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'work_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
        abort_if(
            $target->scope === EmployeeTarget::SCOPE_EMPLOYEE && (int) $target->employee_id !== (int) $data['employee_id'],
            422,
            'This target belongs to another employee.',
        );
        abort_unless(
            $data['work_date'] >= $target->starts_on->toDateString() && $data['work_date'] <= $target->ends_on->toDateString(),
            422,
            'Progress date must fall inside the target dates.',
        );

        $target = DB::transaction(function () use ($target, $data) {
            EmployeeTargetProgress::updateOrCreate(
                [
                    'employee_target_id' => $target->id,
                    'employee_id' => $data['employee_id'],
                    'work_date' => $data['work_date'],
                ],
                [
                    'amount' => $data['amount'],
                    'notes' => $data['notes'] ?? null,
                ],
            );
            $target->refreshProgress();

            return $target->refresh()->load(['employee', 'progressLogs.employee:id,name']);
        });

        return $this->moneyJson($target);
    }

    public function destroy(EmployeeTarget $target): JsonResponse
    {
        $target->delete();

        return response()->json(null, 204);
    }
}
