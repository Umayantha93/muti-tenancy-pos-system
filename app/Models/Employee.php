<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name', 'nic', 'phone', 'position', 'base_salary', 'overtime_hourly_rate', 'fingerprint_id',
    'active', 'epf_enabled', 'paid_leave_days_per_year', 'allowances', 'default_shift_id',
])]
class Employee extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'epf_enabled' => 'boolean',
            'base_salary' => 'decimal:2',
            'allowances' => 'array',
        ];
    }

    public function user(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function attendance(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function payrolls(): HasMany
    {
        return $this->hasMany(Payroll::class);
    }

    public function defaultShift(): BelongsTo
    {
        return $this->belongsTo(WorkShift::class, 'default_shift_id');
    }

    public function leaves(): HasMany
    {
        return $this->hasMany(EmployeeLeave::class);
    }

    public function targets(): HasMany
    {
        return $this->hasMany(EmployeeTarget::class);
    }

    public function shiftAssignments(): HasMany
    {
        return $this->hasMany(ShiftAssignment::class);
    }

    public function bills(): BelongsToMany
    {
        return $this->belongsToMany(Bill::class, 'bill_employees')
            ->withTimestamps();
    }
}
