<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\StampsBranch;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id', 'month', 'year', 'days_present', 'days_absent', 'hours_worked', 'overtime_hours',
    'base_salary', 'overtime_pay', 'allowances_total', 'target_incentive', 'gross_pay',
    'epf_employee', 'epf_employer', 'etf_employer', 'unpaid_leave_days',
    'bonus', 'deductions', 'net_salary', 'generated_at', 'generated_by', 'branch_id',
])]
class Payroll extends Model
{
    use BelongsToTenant, StampsBranch;
    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
            'hours_worked' => 'decimal:2',
            'overtime_hours' => 'decimal:2',
            'base_salary' => 'decimal:2',
            'overtime_pay' => 'decimal:2',
            'allowances_total' => 'decimal:2',
            'target_incentive' => 'decimal:2',
            'gross_pay' => 'decimal:2',
            'epf_employee' => 'decimal:2',
            'epf_employer' => 'decimal:2',
            'etf_employer' => 'decimal:2',
            'bonus' => 'decimal:2',
            'deductions' => 'decimal:2',
            'net_salary' => 'decimal:2',
        ];
    }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
}
