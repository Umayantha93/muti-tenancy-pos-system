<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['employee_id', 'month', 'year', 'days_present', 'days_absent', 'hours_worked', 'overtime_hours', 'base_salary', 'overtime_pay', 'bonus', 'deductions', 'net_salary', 'generated_at', 'generated_by'])]
class Payroll extends Model
{
    use BelongsToTenant;
    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
            'hours_worked' => 'decimal:2',
            'overtime_hours' => 'decimal:2',
            'base_salary' => 'decimal:2',
            'overtime_pay' => 'decimal:2',
            'bonus' => 'decimal:2',
            'deductions' => 'decimal:2',
            'net_salary' => 'decimal:2',
        ];
    }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
}
