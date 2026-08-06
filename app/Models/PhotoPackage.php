<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'price', 'duration_minutes', 'description', 'active'])]
class PhotoPackage extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'active' => 'boolean',
        ];
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(PhotoBooking::class);
    }
}
