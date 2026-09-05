<?php

namespace App\Http\Middleware;

use App\Models\Branch;
use App\Support\BranchContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveBranchContext
{
    public function handle(Request $request, Closure $next): Response
    {
        BranchContext::clear();
        $user = $request->user();
        if (! $user || $user->role === 'super_admin' || ! $user->tenant_id) {
            return $next($request);
        }

        $defaultId = $user->last_branch_id
            ?: $user->home_branch_id
            ?: Branch::defaultIdFor($user->tenant_id);
        $header = $request->header('X-Branch-Id');
        $requested = is_numeric($header) ? (int) $header : null;

        if ($user->role === 'staff') {
            $home = $user->home_branch_id ?: $defaultId;
            if ($requested && $home && $requested !== (int) $home && $request->isMethodSafe() === false) {
                abort(403, 'Staff can only work in their assigned shop.');
            }
            BranchContext::set($home ? (int) $home : null, locked: true);

            return $next($request);
        }

        if ($requested) {
            $branch = Branch::query()
                ->where('tenant_id', $user->tenant_id)
                ->whereKey($requested)
                ->first();
            abort_unless($branch, 404, 'Shop not found.');
            abort_unless($branch->isActive(), 422, 'This shop is inactive.');
            BranchContext::set((int) $branch->id);

            return $next($request);
        }

        if ($defaultId) {
            BranchContext::set((int) $defaultId);
        }

        return $next($request);
    }
}
