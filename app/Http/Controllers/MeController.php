<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\ShiftAssignment;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MeController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user()->load(['tenant', 'employee.defaultShift']);

        return response()->json([
            'user' => $user,
            'employee' => $user->employee,
            'features' => $user->accessibleFeatureKeys(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['nullable', 'regex:/^[0-9+() -]{7,20}$/'],
        ]);

        if (isset($data['name'])) {
            $user->update(['name' => $data['name']]);
            if ($user->employee) {
                $user->employee->update(['name' => $data['name']]);
            }
        }
        if (array_key_exists('phone', $data) && $user->employee) {
            $user->employee->update(['phone' => $data['phone']]);
        }

        return response()->json($user->refresh()->load(['tenant', 'employee']));
    }

    public function leaves(Request $request): JsonResponse
    {
        $employee = $this->linkedEmployee($request);
        $year = $request->integer('year', now()->year);

        return response()->json([
            'employee' => $employee,
            'year' => $year,
            'data' => EmployeeLeave::query()
                ->where('employee_id', $employee->id)
                ->whereYear('start_date', $year)
                ->latest('start_date')
                ->get(),
        ]);
    }

    public function applyLeave(Request $request): JsonResponse
    {
        $employee = $this->linkedEmployee($request);
        $data = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'type' => ['required', Rule::in(['paid', 'unpaid', 'medical'])],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
        $days = Carbon::parse($data['start_date'])->diffInDays(Carbon::parse($data['end_date'])) + 1;

        if ($data['type'] === 'paid' && $employee->paid_leave_days_per_year !== null) {
            $used = (int) EmployeeLeave::query()
                ->where('employee_id', $employee->id)
                ->where('type', 'paid')
                ->whereIn('status', [EmployeeLeave::STATUS_PENDING, EmployeeLeave::STATUS_APPROVED])
                ->whereYear('start_date', Carbon::parse($data['start_date'])->year)
                ->sum('days');
            abort_if($used + $days > $employee->paid_leave_days_per_year, 422, 'Not enough paid leave remaining this year.');
        }

        $leave = EmployeeLeave::create([
            ...$data,
            'employee_id' => $employee->id,
            'days' => $days,
            'status' => EmployeeLeave::STATUS_PENDING,
            'requested_by' => $request->user()->id,
        ]);

        return response()->json($leave->load('employee'), 201);
    }

    public function shifts(Request $request): JsonResponse
    {
        $employee = $this->linkedEmployee($request);

        return response()->json([
            'employee' => $employee->load('defaultShift'),
            'assignments' => ShiftAssignment::with('shift')
                ->where('employee_id', $employee->id)
                ->orderByDesc('starts_on')
                ->get(),
        ]);
    }

    private function linkedEmployee(Request $request): Employee
    {
        $employee = $request->user()->employee;
        abort_unless($employee, 422, 'This login is not linked to a team member. Ask the owner to link your staff account.');

        return $employee;
    }
}
