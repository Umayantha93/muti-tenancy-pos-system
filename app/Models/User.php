<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['tenant_id', 'employee_id', 'name', 'email', 'password', 'role', 'status', 'is_secondary_view'])]
#[Hidden(['password', 'remember_token', 'is_secondary_view'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    public function tenant(): BelongsTo { return $this->belongsTo(Tenant::class); }
    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function permissions(): BelongsToMany { return $this->belongsToMany(Feature::class, 'user_permissions')->withPivot('can_access')->withTimestamps(); }

    public function linkedEmployee(): ?Employee
    {
        return $this->employee;
    }

    public function usesSecondaryFinancialView(): bool
    {
        return (bool) $this->is_secondary_view && (bool) $this->tenant?->dual_financial_view_enabled;
    }

    public function canAccessFeature(string $key): bool
    {
        if ($this->role === 'super_admin') {
            return true;
        }

        $tenantEnabled = $this->tenant?->features()
            ->where('features.key', $key)
            ->wherePivot('is_enabled', true)
            ->exists() ?? false;

        if (! $tenantEnabled) {
            return false;
        }

        return $this->role === 'business_owner' || $this->permissions()
            ->where('features.key', $key)
            ->wherePivot('can_access', true)
            ->exists();
    }

    public function accessibleFeatureKeys(): array
    {
        if ($this->role === 'super_admin') {
            return Feature::pluck('key')->all();
        }

        $enabled = $this->tenant?->features()->wherePivot('is_enabled', true)->pluck('features.key') ?? collect();

        if ($this->role === 'business_owner') {
            return $enabled->values()->all();
        }

        $permitted = $this->permissions()->wherePivot('can_access', true)->pluck('features.key');

        return $enabled->intersect($permitted)->values()->all();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_secondary_view' => 'boolean',
        ];
    }
}
