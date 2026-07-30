<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['business_name', 'business_type', 'owner_name', 'owner_phone', 'owner_email', 'status', 'plan'])]
class Tenant extends Model
{
    use SoftDeletes;

    public function users(): HasMany { return $this->hasMany(User::class); }
    public function features(): BelongsToMany { return $this->belongsToMany(Feature::class, 'tenant_features')->withPivot('is_enabled')->withTimestamps(); }
}
