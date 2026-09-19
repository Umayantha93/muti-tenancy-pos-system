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
    'size_bytes',
])]
class BillPhoto extends Model
{
    use BelongsToTenant;

    public const MAX_PER_BILL = 15;

    public const MAX_UPLOAD_KILOBYTES = 12288;

    public const RETAIN_DAYS = 180;

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

    public function expiresAt(): \Illuminate\Support\Carbon
    {
        return $this->created_at?->copy()->addDays(self::RETAIN_DAYS) ?? now()->addDays(self::RETAIN_DAYS);
    }
}
