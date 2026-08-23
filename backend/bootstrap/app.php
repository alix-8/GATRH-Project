<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Define the 'role' middleware inline
        $middleware->alias([
            'role' => function (Request $request, \Closure $next, ...$roles) {
                if (! $request->user()) {
                    return response()->json(['message' => 'Unauthenticated.'], 401);
                }

                // Uses the hasRole helper on our User model
                if (! $request->user()->hasRole($roles)) {
                    return response()->json([
                        'message' => 'Forbidden: You do not have permission to perform this action.'
                    ], 403);
                }

                return $next($request);
            }
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
