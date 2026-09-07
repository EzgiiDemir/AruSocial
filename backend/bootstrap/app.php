<?php

use App\Http\Middleware\AttachSentryContext;
use App\Http\Middleware\EnsureDepartmentHead;
use App\Http\Middleware\EnsureNotBanned;
use App\Http\Middleware\EnsurePermission;
use App\Support\TrustedProxyList;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Sentry\Laravel\Integration as SentryIntegration;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Host header check (P3-7 §12): defaults to config('app.url')'s own
        // host + subdomains (Illuminate\Http\Middleware\TrustHosts, no args
        // needed here) — the same public origin EnvironmentGuard already
        // requires for staging/production, so nothing new to configure.
        // The middleware's own shouldSpecifyTrustedHosts() already skips
        // local dev and phpunit, so this is always safe to enable.
        $middleware->trustHosts();

        // Reverse proxy header trust (P3-7 §13): OFF by default (no
        // TRUSTED_PROXIES set) so a bare `php artisan serve` never trusts
        // X-Forwarded-* from just anyone. A real deployment behind nginx/a
        // load balancer sets TRUSTED_PROXIES to that proxy's IP(s), or "*"
        // to trust whatever host actually connected (the standard choice
        // when Laravel is never reachable except through that proxy).
        $trustedProxies = TrustedProxyList::parse(env('TRUSTED_PROXIES'));
        if ($trustedProxies !== null) {
            $middleware->trustProxies(at: $trustedProxies);
        }

        // This is a pure JSON API with no HTML forms — Laravel's default
        // ConvertEmptyStringsToNull middleware silently turns any
        // explicitly-sent empty string ("") into null before it reaches a
        // controller, which broke every `$request->input('field', '')`
        // fallback for a genuinely-blank field (found via a real 500:
        // saving an event with an empty "Saat" violated events.time's
        // NOT NULL constraint, because the empty string became null and
        // bypassed the '' default). Removed so the client's actual JSON
        // values round-trip unchanged.
        $middleware->remove(ConvertEmptyStringsToNull::class);
        $middleware->alias([
            'not-banned' => EnsureNotBanned::class,
            'permission' => EnsurePermission::class,
            'department-head' => EnsureDepartmentHead::class,
            'sentry-context' => AttachSentryContext::class,
        ]);

        // There is no login page to send a browser to — this app serves an
        // API and nothing else. Laravel's default guest redirect calls
        // route('login'), which throws a 500 "Route [login] not defined"
        // instead of an honest 401 for any unauthenticated request that
        // doesn't ask for JSON: a plain curl, or the URL pasted into a
        // browser. Returning null leaves it an AuthenticationException,
        // which withExceptions() below renders as the standard 401 envelope.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Reports every exception Laravel would normally report (already
        // excludes AuthenticationException/ValidationException/404s via the
        // handler's default $dontReport) to Sentry, purely as an additional
        // `reportable()` callback — it never renders a response, so the
        // {data, meta, error} envelope below and every existing status code
        // are completely unaffected. No-ops safely when SENTRY_DSN is empty.
        SentryIntegration::handles($exceptions);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // `auth:sanctum` rejects a tokenless request by throwing this, and
        // Laravel's default body is {"message": "Unauthenticated."} — not
        // the {data, meta, error} envelope every client parser (and
        // ApiClient's error-code extraction) expects. Rendered here rather
        // than per-controller so it can't be forgotten on a new route.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'data' => null,
                'meta' => ['request_id' => 'req-'.Str::uuid()],
                'error' => ['code' => 'AUTH_REQUIRED', 'message' => 'Authentication required.'],
            ], 401);
        });
    })->create();
