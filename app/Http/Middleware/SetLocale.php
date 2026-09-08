<?php

namespace App\Http\Middleware;

use App\Support\AppLocale;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $fromHeader = $request->header('X-Locale');
        $fromQuery = $request->query('locale');
        $user = $request->user() ?? Auth::guard('sanctum')->user();
        $locale = AppLocale::normalize(
            is_string($fromQuery) && $fromQuery !== ''
                ? $fromQuery
                : ($fromHeader ?: $user?->locale)
        );
        app()->setLocale($locale);

        return $next($request);
    }
}
