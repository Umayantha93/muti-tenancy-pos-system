<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * How one vehicle type uses one blueprint service. No row = offered, price typed on the job card.
 */
#[Fillable([
    'service_addon_id',
    'service_vehicle_class_id',
    'price',
    'offered',
])]
class ServiceAddonPrice extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'offered' => 'boolean',
        ];
    }
}
