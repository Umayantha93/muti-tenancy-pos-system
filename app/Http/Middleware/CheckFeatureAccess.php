<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckFeatureAccess
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        abort_unless($request->user()?->canAccessFeature($feature), 403, "The {$feature} feature is not available for this account.");

        return $next($request);
    }
}
