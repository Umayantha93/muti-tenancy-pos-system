<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'nic', 'phone', 'position', 'base_salary', 'overtime_hourly_rate', 'fingerprint_id', 'active'])]
class Employee extends Model
{
    use BelongsToTenant;
    protected function casts(): array { return ['active' => 'boolean', 'base_salary' => 'decimal:2']; }
    public function attendance(): HasMany { return $this->hasMany(Attendance::class); }
    public function payrolls(): HasMany { return $this->hasMany(Payroll::class); }
}
