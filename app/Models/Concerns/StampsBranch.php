<?php

namespace App\Models\Concerns;

use App\Models\Branch;
use App\Support\BranchContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

trait StampsBranch
{
    public static function bootStampsBranch(): void
    {
        static::addGlobalScope('branch', function (Builder $builder): void {
            $user = Auth::user();
            if (! $user || $user->role !== 'staff') {
                return;
            }
            $branchId = BranchContext::id();
            if ($branchId) {
                $builder->where($builder->qualifyColumn('branch_id'), $branchId);
            }
        });

        static::creating(function ($model): void {
            if ($model->branch_id) {
                return;
            }
            $id = BranchContext::id();
            if (! $id && $model->tenant_id) {
                $id = Branch::defaultIdFor($model->tenant_id);
            }
            if ($id) {
                $model->branch_id = $id;
            }
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
