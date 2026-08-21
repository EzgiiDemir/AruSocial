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
        // This is a pure JSON API with no HTML forms — Laravel's default
        // ConvertEmptyStringsToNull middleware silently turns any
        // explicitly-sent empty string ("") into null before it reaches a
        // controller, which broke every `$request->input('field', '')`
        // fallback for a genuinely-blank field (found via a real 500:
        // saving an event with an empty "Saat" violated events.time's
        // NOT NULL constraint, because the empty string became null and
        // bypassed the '' default). Removed so the client's actual JSON
        // values round-trip unchanged.
        $middleware->remove(\Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class);
        $middleware->alias([
            'not-banned' => \App\Http\Middleware\EnsureNotBanned::class,
            'permission' => \App\Http\Middleware\EnsurePermission::class,
        ]);
        // Pure JSON API, no `login` named route to redirect an
        // unauthenticated request to — Laravel's default `Authenticate`
        // middleware calls route('login') to build that redirect and,
        // finding none, throws a real RouteNotFoundException that masked
        // the intended 401 as a 500. This makes "no/expired token" render
        // as the clean 401 auth:sanctum is actually supposed to produce.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // Same {data, meta, error} envelope every other endpoint uses (see
        // ApiResponds::fail()) — a missing/expired token would otherwise
        // render Laravel's default {"message": "Unauthenticated."} shape,
        // which ApiClient can still fall back to parsing but without a
        // real error.code the way every other real failure has one.
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'data' => null,
                'meta' => ['request_id' => 'req-'.\Illuminate\Support\Str::uuid()],
                'error' => ['code' => 'UNAUTHENTICATED', 'message' => 'A valid session token is required.'],
            ], 401);
        });
    })->create();
