<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'phone', 'notes'])]
class Supplier extends Model
{
    use BelongsToTenant;

    public function receipts(): HasMany
    {
        return $this->hasMany(StockReceipt::class);
    }
}
