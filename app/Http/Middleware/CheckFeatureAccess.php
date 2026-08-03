<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckFeatureAccess
{
    public function handle(Request $request, Closure $next, string ...$features): Response
    {
        $user = $request->user();
        $allowed = collect($features)->contains(fn (string $feature) => $user?->canAccessFeature($feature));

        abort_unless($allowed, 403, 'This feature is not available for this account.');

        return $next($request);
    }
}
