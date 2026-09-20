<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\StampsBranch;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'part_id',
    'serial',
    'status',
    'bill_item_id',
    'sold_at',
    'notes',
    'branch_id',
])]
class PartSerial extends Model
{
    use BelongsToTenant, StampsBranch;

    public const IN_STOCK = 'in_stock';

    public const SOLD = 'sold';

    public const RETURNED = 'returned';

    protected function casts(): array
    {
        return [
            'sold_at' => 'datetime',
        ];
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    public function billItem(): BelongsTo
    {
        return $this->belongsTo(BillItem::class);
    }

    public static function normalize(string $serial): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim($serial)) ?? '');
    }
}
