<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['employee_id', 'scope', 'starts_on', 'ends_on', 'kind', 'amount', 'progress_amount', 'incentive_amount'])]
class EmployeeTarget extends Model
{
    use BelongsToTenant;

    public const SCOPE_EMPLOYEE = 'employee';

    public const SCOPE_TEAM = 'team';

    protected function casts(): array
    {
        return [
            'starts_on' => 'date:Y-m-d',
            'ends_on' => 'date:Y-m-d',
            'amount' => 'decimal:2',
            'progress_amount' => 'decimal:2',
            'incentive_amount' => 'decimal:2',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function progressLogs(): HasMany
    {
        return $this->hasMany(EmployeeTargetProgress::class);
    }

    public function isTeam(): bool
    {
        return $this->scope === self::SCOPE_TEAM;
    }

    public function refreshProgress(): void
    {
        $this->update([
            'progress_amount' => round((float) $this->progressLogs()->sum('amount'), 2),
        ]);
    }
}
