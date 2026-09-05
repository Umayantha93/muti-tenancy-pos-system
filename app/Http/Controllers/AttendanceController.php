<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Employee;
use App\Services\OvertimeCalculator;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AttendanceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['nullable', Rule::exists('employees', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'year' => ['nullable', 'integer', 'between:2020,2100'],
        ]);

        return response()->json(Attendance::with('employee')
            ->tap(fn ($query) => \App\Support\BranchQuery::constrainViaEmployee($query))
            ->when(isset($data['employee_id']), fn ($query) => $query->where('employee_id', $data['employee_id']))
            ->when(isset($data['month']), fn ($query) => $query->whereMonth('date', $data['month']))
            ->when(isset($data['year']), fn ($query) => $query->whereYear('date', $data['year']))
            ->latest('date')->paginate($request->integer('per_page', 31)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', Rule::exists('employees', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'check_in' => ['required', 'date'],
            'check_out' => ['nullable', 'date', 'after:check_in'],
            'source' => ['nullable', 'string', 'max:50'],
        ]);

        return response()->json($this->saveAttendance(Employee::findOrFail($data['employee_id']), $data)->load('employee'), 201);
    }

    public function punch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fingerprint_id' => ['required', 'string'],
            'event' => ['nullable', Rule::in(['check_in', 'check_out'])],
            'timestamp' => ['nullable', 'date'],
        ]);

        $employee = Employee::query()
            ->where('fingerprint_id', $data['fingerprint_id'])
            ->where('active', true)
            ->firstOrFail();

        $timestamp = Carbon::parse($data['timestamp'] ?? now());
        $existing = Attendance::where('employee_id', $employee->id)
            ->whereBetween('date', [$timestamp->copy()->startOfDay(), $timestamp->copy()->endOfDay()])
            ->first();
        $event = $data['event'] ?? ($existing ? 'check_out' : 'check_in');

        $attendance = $this->saveAttendance($employee, [
            'check_in' => $event === 'check_in' ? $timestamp : ($existing?->check_in ?? $timestamp),
            'check_out' => $event === 'check_out' ? $timestamp : $existing?->check_out,
            'source' => 'fingerprint',
        ]);

        return response()->json($attendance->load('employee'), $existing ? 200 : 201);
    }

    public function ingest(Request $request): JsonResponse
    {
        abort_unless(hash_equals((string) config('services.fingerprint.key'), (string) $request->header('X-Device-Key')), 401, 'Invalid device key.');
        $data = $request->validate([
            'tenant_id' => ['required', 'exists:tenants,id'],
            'fingerprint_id' => ['required', 'string'],
            'timestamp' => ['required', 'date'],
            'event' => ['nullable', Rule::in(['check_in', 'check_out'])],
        ]);
        $employee = Employee::where('tenant_id', $data['tenant_id'])
            ->where('fingerprint_id', $data['fingerprint_id'])->where('active', true)->firstOrFail();
        abort_unless($employee->tenant?->status === 'active' && $employee->tenant->features()
            ->where('features.key', 'attendance')->wherePivot('is_enabled', true)->exists(), 403, 'Attendance is disabled for this business.');
        $timestamp = Carbon::parse($data['timestamp']);
        $existing = Attendance::where('employee_id', $employee->id)
            ->whereBetween('date', [$timestamp->copy()->startOfDay(), $timestamp->copy()->endOfDay()])
            ->first();
        $event = $data['event'] ?? ($existing ? 'check_out' : 'check_in');

        $attendance = $this->saveAttendance($employee, [
            'check_in' => $event === 'check_in' ? $timestamp : ($existing?->check_in ?? $timestamp),
            'check_out' => $event === 'check_out' ? $timestamp : $existing?->check_out,
            'source' => 'fingerprint',
        ]);

        return response()->json($attendance, $existing ? 200 : 201);
    }

    private function saveAttendance(Employee $employee, array $data): Attendance
    {
        return DB::transaction(function () use ($employee, $data) {
            $checkIn = Carbon::parse($data['check_in']);
            $checkOut = isset($data['check_out']) && $data['check_out'] ? Carbon::parse($data['check_out']) : null;
            $overtime = app(OvertimeCalculator::class)->forPunch($employee, $checkIn, $checkOut);

            $attendance = Attendance::where('employee_id', $employee->id)
                ->whereBetween('date', [$checkIn->copy()->startOfDay(), $checkIn->copy()->endOfDay()])
                ->first() ?? new Attendance(['employee_id' => $employee->id, 'date' => $checkIn->toDateString()]);
            $attendance->tenant_id = $employee->tenant_id;
            $attendance->branch_id = $employee->home_branch_id;
            $attendance->fill([
                'check_in' => $checkIn,
                'check_out' => $checkOut,
                'hours_worked' => $overtime['hours_worked'],
                'overtime_hours' => $overtime['overtime_hours'],
                'source' => $data['source'] ?? 'manual',
            ])->save();

            return $attendance;
        });
    }
}
