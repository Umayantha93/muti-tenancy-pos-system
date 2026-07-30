<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'phone', 'address'])]
class Customer extends Model
{
    use BelongsToTenant;
    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }
}
