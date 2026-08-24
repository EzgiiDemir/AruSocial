<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Sentry\State\Scope;
use Sentry\UserDataBag;
use Symfony\Component\HttpFoundation\Response;

// Ties a Sentry event to the same request_id the {data, meta, error}
// envelope returns (ApiResponds::requestId() reads the same request
// attribute), plus the authenticated user's id and route name — nothing
// else (send_default_pii is false; no email/name here, see
// docs/AUDIT_GERCEK_URUN.md P3-6).
//
// Uses the raw \Sentry\configureScope() rather than the Laravel-specific
// Sentry\Laravel\Integration::configureScope() wrapper: the latter is a
// no-op unless the currently bound client has that specific integration
// registered, which isn't guaranteed for every client a test binds.
class AttachSentryContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->attributes->get('_request_id');
        if (! is_string($requestId) || $requestId === '') {
            $requestId = 'req-'.Str::uuid();
            $request->attributes->set('_request_id', $requestId);
        }

        \Sentry\configureScope(function (Scope $scope) use ($request, $requestId): void {
            $scope->setTag('request_id', $requestId);

            $route = $request->route();
            if ($route !== null) {
                $name = $route->getName();
                $scope->setTag('route', is_string($name) && $name !== '' ? $name : $route->uri());
            }

            $user = $request->user();
            if ($user !== null) {
                $scope->setUser(UserDataBag::createFromArray(['id' => $user->getAuthIdentifier()]));
            }
        });

        return $next($request);
    }
}
