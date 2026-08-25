<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\ShiftAssignment;
use App\Models\WorkShift;
use Carbon\Carbon;

class OvertimeCalculator
{
    /**
     * @return array{hours_worked: float, overtime_hours: float}
     */
    public function forPunch(Employee $employee, Carbon $checkIn, ?Carbon $checkOut): array
    {
        $hours = $checkOut ? round($checkIn->diffInMinutes($checkOut) / 60, 2) : 0.0;
        $normal = $this->normalHours($employee, $checkIn);

        return [
            'hours_worked' => $hours,
            'overtime_hours' => max(0, round($hours - $normal, 2)),
        ];
    }

    public function normalHours(Employee $employee, Carbon $date): float
    {
        $shift = $this->shiftFor($employee, $date);
        if (! $shift) {
            return 8.0;
        }
        if ($shift->paid_hours !== null) {
            return (float) $shift->paid_hours;
        }

        $start = Carbon::parse($date->toDateString().' '.$shift->start_time);
        $end = Carbon::parse($date->toDateString().' '.$shift->end_time);
        if ($end->lte($start)) {
            $end->addDay();
        }

        return round($start->diffInMinutes($end) / 60, 2);
    }

    public function shiftFor(Employee $employee, Carbon $date): ?WorkShift
    {
        $day = $date->toDateString();
        $assignment = ShiftAssignment::query()
            ->with('shift')
            ->where('employee_id', $employee->id)
            ->whereDate('starts_on', '<=', $day)
            ->where(fn ($query) => $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $day))
            ->latest('starts_on')
            ->first();

        if ($assignment) {
            return $assignment->shift;
        }

        $employee->loadMissing('defaultShift');

        return $employee->defaultShift;
    }
}
