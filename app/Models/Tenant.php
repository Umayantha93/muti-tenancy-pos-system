<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['business_name', 'business_type', 'owner_name', 'owner_phone', 'owner_email', 'contact_email', 'contact_phone', 'status', 'plan', 'logo'])]
class Tenant extends Model
{
    use SoftDeletes;

    protected $appends = ['logo_url'];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function features(): BelongsToMany
    {
        return $this->belongsToMany(Feature::class, 'tenant_features')->withPivot('is_enabled')->withTimestamps();
    }

    protected function logoUrl(): Attribute
    {
        return Attribute::get(fn () => $this->logo ? 'storage/'.$this->logo : null);
    }
}
