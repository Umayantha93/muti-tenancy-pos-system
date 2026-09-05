<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['tenant_id', 'name', 'code', 'address', 'phone', 'is_default', 'status'])]
class Branch extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public static function ensureDefault(Tenant $tenant): self
    {
        $existing = static::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first();

        if ($existing) {
            if (! $existing->is_default) {
                $existing->update(['is_default' => true]);
            }

            return $existing;
        }

        return static::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Main',
            'code' => 'MAIN',
            'address' => $tenant->address,
            'phone' => $tenant->contact_phone ?: $tenant->owner_phone,
            'is_default' => true,
            'status' => 'active',
        ]);
    }

    public static function defaultIdFor(?int $tenantId): ?int
    {
        if (! $tenantId) {
            return null;
        }

        $id = static::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->value('id');

        if ($id) {
            return (int) $id;
        }

        $tenant = Tenant::query()->find($tenantId);

        return $tenant ? (int) static::ensureDefault($tenant)->id : null;
    }

    public static function uniqueCodeFor(int $tenantId, string $name): string
    {
        $base = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $name) ?: 'SHOP', 0, 6));
        $code = $base;
        $i = 2;
        while (static::withoutGlobalScopes()->where('tenant_id', $tenantId)->where('code', $code)->exists()) {
            $code = substr($base, 0, 4).$i;
            $i++;
        }

        return $code;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(BranchStock::class);
    }
}
