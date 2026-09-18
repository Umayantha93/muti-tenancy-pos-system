<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\Employee;
use App\Support\BranchQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class JobBoardController extends Controller
{
    public const COLUMNS = ['waiting', 'diagnosis', 'waiting_parts', 'in_progress', 'qc', 'ready'];

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['nullable', 'integer'],
        ]);

        $jobs = Bill::query()
            ->with(['customer:id,name,phone', 'vehicle:id,number_plate,make,model', 'employees:id,name,position'])
            ->whereIn('status', ['open', 'partially_paid', 'owe_in'])
            ->where(fn ($query) => $query->whereNull('job_kind')->orWhere('job_kind', '!=', Bill::JOB_KIND_PARTS_SALE))
            ->when(! empty($data['employee_id']), function ($query) use ($data) {
                $query->whereHas('employees', fn ($employees) => $employees->where('employees.id', $data['employee_id']));
            })
            ->tap(fn ($query) => BranchQuery::constrain($query))
            ->queued()
            ->get();

        $columns = [];
        foreach (self::COLUMNS as $key) {
            $columns[$key] = $jobs->filter(function (Bill $bill) use ($key) {
                $status = $bill->floor_status ?: 'waiting';
                if (! in_array($status, self::COLUMNS, true)) {
                    $status = 'waiting';
                }

                return $status === $key;
            })->values();
        }

        $technicians = Employee::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'position']);

        return $this->moneyJson([
            'columns' => $columns,
            'technicians' => $technicians,
        ]);
    }

    public function update(Request $request, Bill $bill): JsonResponse
    {
        abort_if($bill->isClosed(), 422, 'Closed bills cannot move on the floor board.');

        $data = $request->validate([
            'floor_status' => ['required', Rule::in(self::COLUMNS)],
        ]);
        $bill->update(['floor_status' => $data['floor_status'], 'updated_by' => $request->user()->id]);

        return $this->moneyJson($bill->refresh()->load(['customer:id,name,phone', 'vehicle:id,number_plate,make,model', 'employees:id,name,position']));
    }
}
