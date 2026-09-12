<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'bill_refund_id',
    'bill_item_id',
    'quantity',
    'unit_price',
    'amount',
    'disposition',
])]
class BillRefundItem extends Model
{
    use BelongsToTenant;

    public const DISPOSITION_RESTOCK = 'restock';

    public const DISPOSITION_WRITE_OFF = 'write_off';

    public const DISPOSITION_NONE = 'none';

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'amount' => 'decimal:2',
        ];
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(BillRefund::class, 'bill_refund_id');
    }

    public function billItem(): BelongsTo
    {
        return $this->belongsTo(BillItem::class, 'bill_item_id');
    }
}
