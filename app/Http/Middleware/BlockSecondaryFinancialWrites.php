<?php

namespace App\Http\Middleware;

use App\Services\MonetaryView;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BlockSecondaryFinancialWrites
{
    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        if (MonetaryView::for($request->user())->active()) {
            return response()->json([
                'message' => 'This action is not permitted for your account.',
            ], 403);
        }

        return $next($request);
    }
}
