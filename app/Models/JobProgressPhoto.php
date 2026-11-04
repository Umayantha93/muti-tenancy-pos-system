<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'bill_id',
    'step_key',
    'path',
    'original_name',
    'size_bytes',
])]
class JobProgressPhoto extends Model
{
    use BelongsToTenant;

    public const MAX_PER_STEP = 6;

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }
}
