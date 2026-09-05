<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\User;

class SessionPayload
{
    /**
     * @return array{user: User, features: list<string>, branches: mixed, active_branch: ?Branch, can_switch_branch: bool}
     */
    public static function for(User $user): array
    {
        $user->load(['tenant', 'employee', 'homeBranch']);
        $branches = $user->tenant_id
            ? Branch::query()
                ->where('tenant_id', $user->tenant_id)
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get()
            : collect();

        return [
            'user' => $user,
            'features' => $user->accessibleFeatureKeys(),
            'branches' => $branches,
            'active_branch' => BranchContext::branch(),
            'can_switch_branch' => $user->role === 'business_owner' && $branches->where('status', 'active')->count() > 1,
        ];
    }
}
