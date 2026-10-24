<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['employee_id', 'year', 'month', 'days', 'half_days', 'additions', 'deductions'])]
class EmployeePayMonth extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'days' => 'integer',
            'half_days' => 'integer',
            'additions' => 'array',
            'deductions' => 'array',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
