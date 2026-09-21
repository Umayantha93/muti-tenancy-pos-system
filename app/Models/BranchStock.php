<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['tenant_id', 'branch_id', 'part_id', 'product_id', 'qty'])]
class BranchStock extends Model
{
    use BelongsToTenant;

    protected function qty(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value): int|float {
                $n = round((float) $value, 3);

                return fmod($n, 1.0) == 0.0 ? (int) $n : $n;
            },
        );
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
