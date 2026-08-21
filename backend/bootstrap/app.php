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
        $middleware->alias(['not-banned' => \App\Http\Middleware\EnsureNotBanned::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
