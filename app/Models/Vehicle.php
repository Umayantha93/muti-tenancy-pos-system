<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['customer_id', 'number_plate', 'chassis_number', 'make', 'model', 'year'])]
class Vehicle extends Model
{
    use BelongsToTenant;
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }
}
