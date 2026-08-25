<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['employee_target_id', 'employee_id', 'work_date', 'amount', 'notes'])]
class EmployeeTargetProgress extends Model
{
    use BelongsToTenant;

    protected $table = 'employee_target_progresses';

    protected function casts(): array
    {
        return [
            'work_date' => 'date:Y-m-d',
            'amount' => 'decimal:2',
        ];
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(EmployeeTarget::class, 'employee_target_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
