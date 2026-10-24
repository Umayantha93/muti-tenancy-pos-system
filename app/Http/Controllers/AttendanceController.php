<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\EmployeePayMonth;
use App\Services\EmployeeMonthPay;
use App\Services\OvertimeCalculator;
use App\Support\BranchQuery;
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

    public function paySheet(Request $request): JsonResponse
    {
        $data = $request->validate([
            'month' => ['required', 'integer', 'between:1,12'],
            'year' => ['required', 'integer', 'between:2020,2100'],
        ]);

        $shopId = BranchQuery::idForRead();
        $employees = Employee::query()
            ->where('active', true)
            ->when($shopId, fn ($query) => $query->where(function ($inner) use ($shopId) {
                $inner->where('home_branch_id', $shopId)->orWhereNull('home_branch_id');
            }))
            ->orderBy('name')
            ->get();
        $sheets = EmployeePayMonth::query()
            ->whereIn('employee_id', $employees->pluck('id'))
            ->where('year', $data['year'])
            ->where('month', $data['month'])
            ->get()
            ->keyBy('employee_id');

        return response()->json([
            'year' => (int) $data['year'],
            'month' => (int) $data['month'],
            'employees' => $employees->map(fn (Employee $employee) => $this->payRow($employee, $sheets->get($employee->id)))->values(),
        ]);
    }

    public function savePay(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', Rule::exists('employees', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'year' => ['required', 'integer', 'between:2020,2100'],
            'month' => ['required', 'integer', 'between:1,12'],
            'days' => ['nullable', 'integer', 'min:0', 'max:31'],
            'half_days' => ['nullable', 'integer', 'min:0', 'max:31'],
            'additions' => ['nullable', 'array'],
            'additions.*.name' => ['nullable', 'string', 'max:80'],
            'additions.*.amount' => ['nullable', 'numeric', 'min:0'],
            'deductions' => ['nullable', 'array'],
            'deductions.*.name' => ['nullable', 'string', 'max:80'],
            'deductions.*.amount' => ['nullable', 'numeric', 'min:0'],
        ]);

        $employee = Employee::query()->findOrFail($data['employee_id']);
        $sheet = EmployeePayMonth::query()->updateOrCreate(
            [
                'employee_id' => $employee->id,
                'year' => $data['year'],
                'month' => $data['month'],
            ],
            [
                'days' => (int) ($data['days'] ?? 0),
                'half_days' => (int) ($data['half_days'] ?? 0),
                'additions' => $this->moneyLines($data['additions'] ?? []),
                'deductions' => $this->moneyLines($data['deductions'] ?? []),
            ],
        );

        return response()->json($this->payRow($employee, $sheet->refresh()));
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

    /**
     * @param  list<array{name?: string|null, amount?: float|int|string|null}>  $lines
     * @return list<array{name: string, amount: float}>
     */
    private function moneyLines(array $lines): array
    {
        return collect($lines)
            ->map(fn (array $line) => [
                'name' => trim((string) ($line['name'] ?? '')),
                'amount' => round((float) ($line['amount'] ?? 0), 2),
            ])
            ->filter(fn (array $line) => $line['name'] !== '' && $line['amount'] > 0)
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function payRow(Employee $employee, ?EmployeePayMonth $sheet): array
    {
        $pay = EmployeeMonthPay::calculate($employee, $sheet);

        return [
            'employee_id' => $employee->id,
            'name' => $employee->name,
            'position' => $employee->position,
            'pay_type' => $employee->pay_type ?: 'monthly',
            'amount' => $employee->pay_type === 'daily' ? $employee->daily_rate : $employee->base_salary,
            'days' => (int) ($sheet->days ?? 0),
            'half_days' => (int) ($sheet->half_days ?? 0),
            'additions' => $sheet->additions ?? [],
            'deductions' => $sheet->deductions ?? [],
            'salary' => $pay['salary'],
            'salary_ready' => $pay['ready'],
        ];
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
