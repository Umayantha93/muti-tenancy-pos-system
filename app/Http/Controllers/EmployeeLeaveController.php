<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeLeave;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EmployeeLeaveController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['nullable', Rule::exists('employees', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'year' => ['nullable', 'integer', 'between:2020,2100'],
        ]);

        $year = $data['year'] ?? now()->year;
        $leaves = EmployeeLeave::with(['employee:id,name,paid_leave_days_per_year', 'requester:id,name', 'reviewer:id,name'])
            ->when(isset($data['employee_id']), fn ($query) => $query->where('employee_id', $data['employee_id']))
            ->whereYear('start_date', $year)
            ->latest('start_date')
            ->get();

        return response()->json(['data' => $leaves, 'year' => $year]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', Rule::exists('employees', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'type' => ['required', Rule::in(['paid', 'unpaid', 'medical'])],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
        $days = Carbon::parse($data['start_date'])->diffInDays(Carbon::parse($data['end_date'])) + 1;
        $employee = Employee::findOrFail($data['employee_id']);

        if ($data['type'] === 'paid' && $employee->paid_leave_days_per_year !== null) {
            $used = (int) EmployeeLeave::query()
                ->where('employee_id', $employee->id)
                ->where('type', 'paid')
                ->whereIn('status', [EmployeeLeave::STATUS_PENDING, EmployeeLeave::STATUS_APPROVED])
                ->whereYear('start_date', Carbon::parse($data['start_date'])->year)
                ->sum('days');
            abort_if($used + $days > $employee->paid_leave_days_per_year, 422, 'Not enough paid leave remaining this year.');
        }

        $asOwner = $request->user()->role === 'business_owner';
        $leave = EmployeeLeave::create([
            ...$data,
            'days' => $days,
            'status' => $asOwner ? EmployeeLeave::STATUS_APPROVED : EmployeeLeave::STATUS_PENDING,
            'requested_by' => $request->user()->id,
            'reviewed_by' => $asOwner ? $request->user()->id : null,
            'reviewed_at' => $asOwner ? now() : null,
        ]);

        return response()->json($leave->load('employee'), 201);
    }

    public function approve(Request $request, EmployeeLeave $leave): JsonResponse
    {
        abort_unless($request->user()->role === 'business_owner', 403, 'Only the business owner can approve leave.');
        abort_unless($leave->isPending(), 422, 'This leave is not waiting for approval.');

        $leave->update([
            'status' => EmployeeLeave::STATUS_APPROVED,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_notes' => $request->string('review_notes')->toString() ?: null,
        ]);

        return response()->json($leave->refresh()->load(['employee', 'reviewer:id,name']));
    }

    public function reject(Request $request, EmployeeLeave $leave): JsonResponse
    {
        abort_unless($request->user()->role === 'business_owner', 403, 'Only the business owner can reject leave.');
        abort_unless($leave->isPending(), 422, 'This leave is not waiting for approval.');
        $data = $request->validate(['review_notes' => ['nullable', 'string', 'max:255']]);

        $leave->update([
            'status' => EmployeeLeave::STATUS_REJECTED,
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
            'review_notes' => $data['review_notes'] ?? null,
        ]);

        return response()->json($leave->refresh()->load(['employee', 'reviewer:id,name']));
    }

    public function destroy(EmployeeLeave $leave): JsonResponse
    {
        $leave->delete();

        return response()->json(null, 204);
    }
}
