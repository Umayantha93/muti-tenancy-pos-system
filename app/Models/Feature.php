<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['key', 'name', 'description'])]
class Feature extends Model
{
    public function tenants(): BelongsToMany { return $this->belongsToMany(Tenant::class, 'tenant_features')->withPivot('is_enabled')->withTimestamps(); }
    public function users(): BelongsToMany { return $this->belongsToMany(User::class, 'user_permissions')->withPivot('can_access')->withTimestamps(); }
}
