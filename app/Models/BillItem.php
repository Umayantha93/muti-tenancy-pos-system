<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['bill_id', 'part_id', 'type', 'description', 'quantity', 'unit_price', 'line_total'])]
class BillItem extends Model
{
    use BelongsToTenant;
    public function bill(): BelongsTo { return $this->belongsTo(Bill::class); }
    public function part(): BelongsTo { return $this->belongsTo(Part::class); }
}
