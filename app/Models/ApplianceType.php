<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\BusinessTypes;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

#[Fillable([
    'key',
    'name',
    'sort_order',
    'built_in',
])]
class ApplianceType extends Model
{
    use BelongsToTenant;

    /** @var array<int, array<string, string>> */
    private static array $namesByTenant = [];

    public static function seedDefaultsFor(int $tenantId): void
    {
        if (static::withoutGlobalScopes()->where('tenant_id', $tenantId)->exists()) {
            return;
        }

        $index = 0;
        foreach (BusinessTypes::applianceTypeLabels() as $key => $name) {
            $index++;
            $row = new static;
            $row->forceFill([
                'tenant_id' => $tenantId,
                'key' => $key,
                'name' => $name,
                'sort_order' => $index * 10,
                'built_in' => true,
            ])->save();
        }
    }

    public static function allowed(int $tenantId, ?string $key): bool
    {
        $key = trim((string) $key);
        if ($key === '') {
            return false;
        }
        if (in_array($key, BusinessTypes::applianceTypes(), true)) {
            return true;
        }

        return static::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('key', $key)
            ->exists();
    }

    public static function assertAllowed(int $tenantId, ?string $key): void
    {
        if (static::allowed($tenantId, $key)) {
            return;
        }

        throw ValidationException::withMessages([
            'appliance_type' => ['Choose an appliance from the list.'],
        ]);
    }

    public static function nameFor(int $tenantId, ?string $key): ?string
    {
        $key = trim((string) $key);
        if ($key === '') {
            return null;
        }
        if (in_array($key, BusinessTypes::applianceTypes(), true)) {
            return null;
        }
        if (! isset(self::$namesByTenant[$tenantId])) {
            self::$namesByTenant[$tenantId] = static::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->pluck('name', 'key')
                ->all();
        }

        return self::$namesByTenant[$tenantId][$key] ?? null;
    }

    public static function keyFromName(string $name): string
    {
        $key = Str::slug($name, '_');
        $key = substr($key, 0, 40);

        if ($key === '') {
            throw ValidationException::withMessages([
                'name' => ['Enter an appliance name.'],
            ]);
        }

        return $key;
    }

    protected function casts(): array
    {
        return [
            'built_in' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
