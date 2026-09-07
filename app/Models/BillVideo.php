<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'bill_id',
    'path',
    'original_name',
    'label',
    'duration_seconds',
    'size_bytes',
])]
class BillVideo extends Model
{
    use BelongsToTenant;

    public const MAX_PER_BILL = 5;

    public const MAX_SECONDS = 90;

    public const MAX_OUTPUT_BYTES = 40 * 1024 * 1024;

    public const RETAIN_DAYS = 180;

    protected function casts(): array
    {
        return [
            'duration_seconds' => 'integer',
            'size_bytes' => 'integer',
        ];
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }

    public function expiresAt(): \Illuminate\Support\Carbon
    {
        return $this->created_at?->copy()->addDays(self::RETAIN_DAYS) ?? now()->addDays(self::RETAIN_DAYS);
    }
}
