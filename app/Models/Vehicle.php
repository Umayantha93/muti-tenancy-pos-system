<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\BusinessTypes;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['customer_id', 'asset_kind', 'appliance_type', 'number_plate', 'chassis_number', 'imei', 'tyre_size', 'axle', 'fault_description', 'make', 'model', 'year'])]
class Vehicle extends Model
{
    use BelongsToTenant;

    protected $appends = [
        'appliance_type_name',
    ];

    public function getApplianceTypeNameAttribute(): ?string
    {
        $key = $this->attributes['appliance_type'] ?? null;
        if (! is_string($key) || $key === '' || in_array($key, BusinessTypes::applianceTypes(), true)) {
            return null;
        }
        $tenantId = $this->attributes['tenant_id'] ?? null;
        if (! $tenantId) {
            return null;
        }

        return ApplianceType::nameFor((int) $tenantId, $key);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }
}
