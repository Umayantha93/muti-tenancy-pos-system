<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->status === 'active', 403, 'This user account is inactive.');

        $user = $request->user();
        if ($user?->is_secondary_view && ! $user->tenant?->dual_financial_view_enabled) {
            $user->tokens()->delete();
            abort(403, 'This user account is inactive.');
        }

        return $next($request);
    }
}
