<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'broadcasting/auth'],

    // The real route inventory (133 `/api/v1/*` routes, `docs/API_CONTRACT.md`)
    // only ever uses GET/HEAD, POST and DELETE — no route registers PUT or
    // PATCH. Listed explicitly (P3-7 §10) instead of '*' so a browser can
    // never successfully preflight a method this API doesn't expose.
    'allowed_methods' => ['GET', 'HEAD', 'POST', 'DELETE', 'OPTIONS'],

    // CORS only applies to browser (web) clients — native Android/iOS HTTP
    // requests don't send an Origin header, so this has no effect there
    // (P3-7 §5). Defaults to the web dev server's own origin; set
    // CORS_ALLOWED_ORIGINS (comma-separated) in .env once there's a real
    // staging/production web origin to allow instead. See
    // docs/EKSIKLER.md §1 and docs/ENVIRONMENTS.md. EnvironmentGuard
    // refuses to boot staging/production with this empty or containing a
    // literal "*" — see App\Support\EnvironmentGuard (P3-7 §18).
    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:8090'))
    ))),

    // `flutter run -d chrome` binds a fresh random localhost port on every
    // launch, so no fixed list in CORS_ALLOWED_ORIGINS can keep up with it
    // — every rebuild would otherwise be blocked by preflight. These
    // patterns accept any loopback origin instead, but only outside
    // production, so a deployed API still answers exactly the origins
    // CORS_ALLOWED_ORIGINS names and nothing more.
    'allowed_origins_patterns' => in_array(env('APP_ENV', 'production'), ['local', 'testing'], true)
        ? [
            '#^https?://localhost(:\d+)?$#',
            '#^https?://127\.0\.0\.1(:\d+)?$#',
        ]
        : [],

    // Only what ApiClient (frontend/lib/core/network/api_client.dart) and a
    // browser preflight actually need (P3-7 §8): bearer token, JSON body,
    // JSON accept, and the conventional XHR marker some tooling still
    // sends. No custom request-id header exists client-side to allow —
    // request_id already round-trips in the JSON body (meta.request_id),
    // not a header (P3-7 §9).
    'allowed_headers' => ['Authorization', 'Content-Type', 'Accept', 'X-Requested-With'],

    // request_id is already in every response body (meta.request_id) — no
    // need to additionally expose it as a CORS-readable header (P3-7 §9).
    'exposed_headers' => [],

    'max_age' => 0,

    // Bearer token auth (Sanctum personal access tokens), not cookies —
    // ApiClient never sends credentials: 'include', so there is nothing for
    // this to enable. Keeping it false also means allowed_origins could
    // never accidentally combine with a "*" wildcard into the one CORS
    // combination browsers reject outright (P3-7 §6).
    'supports_credentials' => false,

];
