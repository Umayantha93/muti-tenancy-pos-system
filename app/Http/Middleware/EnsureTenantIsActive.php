<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->role !== 'super_admin') {
            $user?->tenant?->expireDemoIfNeeded();
            abort_unless($user?->tenant && $user->tenant->fresh()?->status === 'active', 403, 'This business account is inactive.');
        }

        return $next($request);
    }
}
