<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\StampsBranch;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'sort_order', 'active', 'branch_id'])]
class Bay extends Model
{
    use BelongsToTenant, StampsBranch;

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(JobBooking::class);
    }

    public static function ensureDefaults(): void
    {
        if (static::query()->tap(fn ($query) => \App\Support\BranchQuery::constrain($query))->exists()) {
            return;
        }

        foreach ([1, 2, 3] as $index) {
            static::create([
                'name' => 'Bay '.$index,
                'sort_order' => $index,
                'active' => true,
            ]);
        }
    }
}
