<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['bill_id', 'amount', 'method', 'reference', 'paid_at', 'received_by'])]
class BillPayment extends Model
{
    use BelongsToTenant;
    protected function casts(): array { return ['paid_at' => 'datetime', 'amount' => 'decimal:2']; }
    public function bill(): BelongsTo { return $this->belongsTo(Bill::class); }
    public function receiver(): BelongsTo { return $this->belongsTo(User::class, 'received_by'); }
}
