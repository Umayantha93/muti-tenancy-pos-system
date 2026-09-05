<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\StampsBranch;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['employee_id', 'date', 'check_in', 'check_out', 'hours_worked', 'overtime_hours', 'source', 'branch_id'])]
class Attendance extends Model
{
    use BelongsToTenant, StampsBranch;
    protected $table = 'attendance';

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'check_in' => 'datetime',
            'check_out' => 'datetime',
            'hours_worked' => 'decimal:2',
            'overtime_hours' => 'decimal:2',
        ];
    }

    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
}
