<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\StampsBranch;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

#[Fillable([
    'bay_id', 'customer_id', 'vehicle_id', 'employee_id', 'bill_id', 'created_by',
    'number_plate', 'starts_at', 'ends_at', 'status', 'notes', 'branch_id',
])]
class JobBooking extends Model
{
    use BelongsToTenant, StampsBranch;

    public const STATUS_BOOKED = 'booked';

    public const STATUS_ARRIVED = 'arrived';

    public const STATUS_CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function bay(): BelongsTo
    {
        return $this->belongsTo(Bay::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function conflictMessage(int $bayId, Carbon $starts, Carbon $ends, ?int $employeeId = null, ?int $ignoreId = null): ?string
    {
        $overlapping = fn () => static::query()
            ->where('status', '!=', self::STATUS_CANCELLED)
            ->where('starts_at', '<', $ends)
            ->where('ends_at', '>', $starts)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId));

        if ($overlapping()->where('bay_id', $bayId)->exists()) {
            return 'This bay is already booked for that time.';
        }

        if ($employeeId && $overlapping()->where('employee_id', $employeeId)->exists()) {
            return 'This technician is already booked for that time.';
        }

        return null;
    }
}
