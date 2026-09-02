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

    public static function resolveFromIntake(?string $name, ?string $phone, ?string $address = null): self
    {
        $name = trim((string) $name) ?: 'Walk-in';
        $phone = trim((string) $phone) ?: null;
        $address = $address !== null && trim($address) !== '' ? trim($address) : null;

        if ($phone) {
            $customer = static::firstOrCreate(
                ['phone' => $phone],
                ['name' => $name, 'address' => $address],
            );
            $updates = [];
            if ($name !== 'Walk-in') {
                $updates['name'] = $name;
            }
            if ($address !== null) {
                $updates['address'] = $address;
            }
            if ($updates) {
                $customer->update($updates);
            }

            return $customer;
        }

        return static::create([
            'name' => $name,
            'phone' => null,
            'address' => $address,
        ]);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function bills(): HasMany
    {
        return $this->hasMany(Bill::class);
    }
}
