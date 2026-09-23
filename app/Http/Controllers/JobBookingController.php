<?php

namespace App\Http\Controllers;

use App\Models\Bay;
use App\Models\Bill;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\JobBooking;
use App\Models\Vehicle;
use App\Support\BranchQuery;
use App\Support\BusinessTypes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class JobBookingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['nullable', 'date'],
        ]);
        $day = Carbon::parse($data['date'] ?? today())->startOfDay();

        $bookings = JobBooking::query()
            ->with(['bay:id,name', 'customer:id,name,phone', 'vehicle:id,number_plate,make,model', 'employee:id,name,position', 'bill:id,bill_number,status'])
            ->tap(fn ($query) => BranchQuery::constrain($query))
            ->where('starts_at', '>=', $day)
            ->where('starts_at', '<', $day->copy()->addDay())
            ->where('status', '!=', JobBooking::STATUS_CANCELLED)
            ->orderBy('starts_at')
            ->get();

        $bays = Bay::query()
            ->tap(fn ($query) => BranchQuery::constrain($query))
            ->where('active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'name', 'sort_order']);

        if ($bays->isEmpty()) {
            Bay::ensureDefaults();
            $bays = Bay::query()
                ->tap(fn ($query) => BranchQuery::constrain($query))
                ->where('active', true)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'name', 'sort_order']);
        }

        $technicians = Employee::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'position']);

        return $this->moneyJson([
            'date' => $day->toDateString(),
            'bays' => $bays,
            'technicians' => $technicians,
            'bookings' => $bookings,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $starts = Carbon::parse($data['starts_at']);
        $ends = Carbon::parse($data['ends_at']);
        abort_if($ends->lessThanOrEqualTo($starts), 422, 'End time must be after the start time.');

        $conflict = JobBooking::conflictMessage((int) $data['bay_id'], $starts, $ends, $data['employee_id'] ?? null);
        abort_if($conflict, 422, $conflict);

        $booking = DB::transaction(fn () => $this->writeBooking($request, $data, $starts, $ends));

        return $this->moneyJson($booking->load($this->relations()), 201);
    }

    public function update(Request $request, JobBooking $job_booking): JsonResponse
    {
        abort_if($job_booking->status === JobBooking::STATUS_CANCELLED, 422, 'This booking is cancelled.');

        $data = $this->validated($request, $job_booking);
        $starts = Carbon::parse($data['starts_at'] ?? $job_booking->starts_at);
        $ends = Carbon::parse($data['ends_at'] ?? $job_booking->ends_at);
        abort_if($ends->lessThanOrEqualTo($starts), 422, 'End time must be after the start time.');

        $bayId = (int) ($data['bay_id'] ?? $job_booking->bay_id);
        $employeeId = array_key_exists('employee_id', $data) ? $data['employee_id'] : $job_booking->employee_id;
        $conflict = JobBooking::conflictMessage($bayId, $starts, $ends, $employeeId, $job_booking->id);
        abort_if($conflict, 422, $conflict);

        $job_booking->update([
            ...$data,
            'starts_at' => $starts,
            'ends_at' => $ends,
        ]);

        return $this->moneyJson($job_booking->refresh()->load($this->relations()));
    }

    public function destroy(JobBooking $job_booking): JsonResponse
    {
        $job_booking->update(['status' => JobBooking::STATUS_CANCELLED]);

        return $this->moneyJson($job_booking->refresh()->load($this->relations()));
    }

    public function openJob(Request $request, JobBooking $job_booking): JsonResponse
    {
        abort_if($job_booking->status === JobBooking::STATUS_CANCELLED, 422, 'This booking is cancelled.');
        $job_booking->loadMissing(['vehicle', 'customer']);
        if ($job_booking->bill_id) {
            return $this->moneyJson($job_booking->load($this->relations()));
        }

        DB::transaction(function () use ($request, $job_booking) {
            $customer = $job_booking->customer_id
                ? Customer::query()->findOrFail($job_booking->customer_id)
                : Customer::resolveFromIntake(null, null);

            $plate = strtoupper((string) ($job_booking->number_plate ?: $job_booking->vehicle?->number_plate ?: 'TBD'));
            $vehicle = $job_booking->vehicle_id
                ? Vehicle::query()->findOrFail($job_booking->vehicle_id)
                : Vehicle::query()->where('number_plate', $plate)->where('customer_id', $customer->id)->first();

            if (! $vehicle) {
                $vehicle = Vehicle::create([
                    'customer_id' => $customer->id,
                    'number_plate' => $plate,
                    'asset_kind' => 'vehicle',
                ]);
            }

            $type = BusinessTypes::normalizeLegacy((string) ($request->user()->tenant?->business_type ?? BusinessTypes::GARAGE));
            $jobKind = BusinessTypes::defaultJobKind($request->user(), $type);
            $prefix = BusinessTypes::billPrefix($type);
            $billNumber = $request->user()->tenant?->claimNextBillNumber()
                ?? ($prefix.'-'.now()->format('Ymd').'-'.strtoupper(str()->random(6)));

            $bill = Bill::create([
                'bill_number' => $billNumber,
                'vehicle_id' => $vehicle->id,
                'customer_id' => $customer->id,
                'admission_date' => today(),
                'notes' => $job_booking->notes,
                'job_kind' => $jobKind,
                'floor_status' => 'waiting',
                'source_type' => JobBooking::class,
                'source_id' => $job_booking->id,
                'created_by' => $request->user()->id,
            ]);

            if ($job_booking->employee_id) {
                $bill->employees()->sync([
                    $job_booking->employee_id => ['tenant_id' => $bill->tenant_id],
                ]);
            }

            $job_booking->update([
                'bill_id' => $bill->id,
                'vehicle_id' => $vehicle->id,
                'customer_id' => $customer->id,
                'number_plate' => $plate,
                'status' => JobBooking::STATUS_ARRIVED,
            ]);

            return $bill;
        });

        return $this->moneyJson($job_booking->refresh()->load($this->relations()));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?JobBooking $booking = null): array
    {
        $tenantId = $request->user()->tenant_id;

        return $request->validate([
            'bay_id' => [$booking ? 'sometimes' : 'required', Rule::exists('bays', 'id')->where('tenant_id', $tenantId)],
            'starts_at' => [$booking ? 'sometimes' : 'required', 'date'],
            'ends_at' => [$booking ? 'sometimes' : 'required', 'date', 'after:starts_at'],
            'employee_id' => ['nullable', Rule::exists('employees', 'id')->where('tenant_id', $tenantId)],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'regex:/^[0-9+() -]{7,20}$/'],
            'number_plate' => [$booking ? 'nullable' : 'required', 'string', 'max:30'],
            'notes' => ['nullable', 'string'],
            'status' => ['sometimes', Rule::in([JobBooking::STATUS_BOOKED, JobBooking::STATUS_ARRIVED, JobBooking::STATUS_CANCELLED])],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function writeBooking(Request $request, array $data, Carbon $starts, Carbon $ends): JobBooking
    {
        $customer = Customer::resolveFromIntake($data['customer_name'] ?? null, $data['customer_phone'] ?? null);
        $plate = strtoupper(trim((string) $data['number_plate']));
        $vehicle = Vehicle::query()
            ->where('number_plate', $plate)
            ->where('customer_id', $customer->id)
            ->first();

        if (! $vehicle) {
            $vehicle = Vehicle::create([
                'customer_id' => $customer->id,
                'number_plate' => $plate,
                'asset_kind' => 'vehicle',
            ]);
        }

        return JobBooking::create([
            'bay_id' => $data['bay_id'],
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'employee_id' => $data['employee_id'] ?? null,
            'created_by' => $request->user()->id,
            'number_plate' => $plate,
            'starts_at' => $starts,
            'ends_at' => $ends,
            'status' => $data['status'] ?? JobBooking::STATUS_BOOKED,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    /**
     * @return list<string>
     */
    private function relations(): array
    {
        return ['bay:id,name', 'customer:id,name,phone', 'vehicle:id,number_plate,make,model', 'employee:id,name,position', 'bill:id,bill_number,status'];
    }
}
