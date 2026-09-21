<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureTenantIsActive;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\CheckFeatureAccess;
use App\Http\Middleware\BlockSecondaryFinancialWrites;
use App\Http\Middleware\ResolveBranchContext;
use App\Http\Middleware\SetLocale;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        \App\Console\Commands\ServeCommand::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            SetLocale::class,
        ]);
        $middleware->prependToPriorityList(
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            ResolveBranchContext::class,
        );
        $middleware->alias([
            'role' => EnsureRole::class,
            'user.active' => EnsureUserIsActive::class,
            'tenant.active' => EnsureTenantIsActive::class,
            'feature' => CheckFeatureAccess::class,
            'block.secondary.writes' => BlockSecondaryFinancialWrites::class,
            'branch.context' => ResolveBranchContext::class,
            'locale' => SetLocale::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
        $exceptions->render(function (\Illuminate\Http\Exceptions\PostTooLargeException $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'This file is too large to upload. Use a smaller photo or a clip under 40 MB.',
                ], 413);
            }
        });
    })->create();
