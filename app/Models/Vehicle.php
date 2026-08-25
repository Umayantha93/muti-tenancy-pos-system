<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['customer_id', 'asset_kind', 'number_plate', 'chassis_number', 'imei', 'tyre_size', 'axle', 'fault_description', 'make', 'model', 'year'])]
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
