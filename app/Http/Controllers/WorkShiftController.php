<?php

namespace App\Http\Controllers;

use App\Models\ShiftAssignment;
use App\Models\WorkShift;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WorkShiftController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(WorkShift::query()->orderBy('start_time')->get());
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(WorkShift::create($this->shiftData($request)), 201);
    }

    public function update(Request $request, WorkShift $workShift): JsonResponse
    {
        $workShift->update($this->shiftData($request, true));

        return response()->json($workShift->refresh());
    }

    public function destroy(WorkShift $workShift): JsonResponse
    {
        $workShift->delete();

        return response()->json(null, 204);
    }

    public function assignments(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $rows = ShiftAssignment::with(['employee:id,name,position', 'shift'])
            ->when(isset($data['from']), fn ($query) => $query->where(fn ($nested) => $nested
                ->whereNull('ends_on')->orWhereDate('ends_on', '>=', $data['from'])))
            ->when(isset($data['to']), fn ($query) => $query->whereDate('starts_on', '<=', $data['to']))
            ->orderBy('starts_on')
            ->get();

        return response()->json($rows);
    }

    public function assign(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', Rule::exists('employees', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'work_shift_id' => ['required', Rule::exists('work_shifts', 'id')->where('tenant_id', $request->user()->tenant_id)],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
        ]);

        return response()->json(ShiftAssignment::create($data)->load(['employee', 'shift']), 201);
    }

    public function destroyAssignment(ShiftAssignment $assignment): JsonResponse
    {
        $assignment->delete();

        return response()->json(null, 204);
    }

    private function shiftData(Request $request, bool $updating = false): array
    {
        foreach (['start_time', 'end_time'] as $key) {
            if ($request->filled($key)) {
                $request->merge([$key => substr((string) $request->input($key), 0, 5)]);
            }
        }

        return $request->validate([
            'name' => [$updating ? 'sometimes' : 'required', 'string', 'max:80'],
            'start_time' => [$updating ? 'sometimes' : 'required', 'date_format:H:i'],
            'end_time' => [$updating ? 'sometimes' : 'required', 'date_format:H:i'],
            'paid_hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
        ]);
    }
}
