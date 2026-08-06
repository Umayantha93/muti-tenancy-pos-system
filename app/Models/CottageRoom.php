<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'capacity', 'nightly_rate', 'status', 'description', 'active'])]
class CottageRoom extends Model
{
    use BelongsToTenant;

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
