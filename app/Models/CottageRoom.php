<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\StampsBranch;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'capacity', 'nightly_rate', 'status', 'description', 'active', 'branch_id'])]
class CottageRoom extends Model
{
    use BelongsToTenant, StampsBranch;

    protected function casts(): array
    {
        return [
            'nightly_rate' => 'decimal:2',
            'active' => 'boolean',
        ];
    }

    public function stays(): HasMany
    {
        return $this->hasMany(CottageStay::class);
    }
}
