# Environments (local / staging / production)

Config isolation only. This is **not** a deployment platform, CI/CD, or
production cutover. Secrets stay in the host `.env` (Laravel) or the
operator’s dart-define / Firebase files (Flutter public values only).

| | Local | Staging | Production |
|---|---|---|---|
| `APP_ENV` | `local` | `staging` | `production` |
| `APP_DEBUG` | `true` | `false` | `false` |
| Database | SQLite | PostgreSQL | PostgreSQL |
| Queue | `database` (phpunit: `sync`) | `database` / redis | `database` / redis |
| Mail | `log` | `log` or `smtp` | `smtp` |
| Reverb | `localhost:8080` | staging host | production host |
| FCM | empty → no-op | staging project | production project |
| Sentry | off | staging DSN (P3-6) | production DSN (P3-6) |
| CORS origins (P3-7) | `localhost:8090` + any loopback port | staging web origin(s) only | production web origin(s) only, never `*` |
| Trusted proxy (P3-7) | none | `TRUSTED_PROXIES` if behind one | `TRUSTED_PROXIES` if behind one |
| Flutter | REST by default; mock only with explicit `USE_REST_API=false` debug build | REST + public API URL | REST + public API URL |
| AICAD fact-bounded answers (`AICAD_SUPPORTED_FACT_GENERATION_ENABLED`) | `off` (set `on` to test) | `on` — review AICAD Health → SupportedFacts rollout | `off` until the staging review passes; `staff_only` as the first production step |
| AICAD campus clock (`AICAD_CAMPUS_TIMEZONE`) | `Europe/Nicosia` | `Europe/Nicosia` | `Europe/Nicosia` |

Source of truth:

- Backend: `.env` → `backend/config/*.php` (`EnvironmentGuard` on boot)
- Flutter: `--dart-define` → `AppConfig.fromEnvironment()`

Never put in Flutter: DB password, `REVERB_APP_SECRET`, Firebase private key,
SMTP password, WP token, moderation key.

---

## Backend

Copy `backend/.env.example` → `backend/.env`. Local defaults are SQLite +
debug. Staging/production blocks at the bottom of `.env.example` are
**placeholders** — fill them on the host, do not commit secrets.

Boot refuses staging/production when it would silently look like local:
SQLite, `APP_DEBUG=true`, localhost `APP_URL` / Reverb, `QUEUE_CONNECTION=sync`,
partial Firebase credentials. `testing` (phpunit) is exempt.

Cache / Redis / session keys include `APP_ENV` so two environments cannot
share uniqueness locks (FCM `ShouldBeUnique`) by accident.

Media URLs: `MEDIA_URL` or `APP_URL` + `FILESYSTEM_MEDIA_DISK` (default
`public`). Schema unchanged.

Mail: local `MAIL_MAILER=log` (no real inbox). Staging may stay `log` or
set `smtp` with host/from/credentials. Production **must** be `smtp`;
missing host/from/username/password fails boot (`EnvironmentGuard`).
Sends stay synchronous (`EmailService` → `Mail::send`) so `email_logs`
status is `sent` or `failed` before the HTTP response. Password is never
written to logs, API, or audit.

Sentry (P3-6): `SENTRY_DSN` empty locally — `sentry/sentry-laravel` boots
either way but a no-op transport skips every capture without one. Staging
and production each set their own DSN so events never get tagged with the
wrong `environment` (falls back to `APP_ENV`, never a shared value). Crash
reporting only: `SENTRY_TRACES_SAMPLE_RATE` stays unset/null (no performance
tracing) unless a host opts in per environment. `App\Support\SentryScrubber`
(`config/sentry.php`'s `before_send`) redacts request passwords/tokens, the
WP API token, moderation key, SMTP credentials and the Firebase private key
before anything leaves the process — see `SentrySecretScrubTest`. FCM job
and `EmailService` send failures are additionally reported explicitly
(`\Sentry\captureException`) without changing their existing swallow/retry
behavior.

### CORS / trusted host / proxy (P3-7)

Browser-only concern — native Android/iOS never sends an `Origin` header, so
none of this affects the Flutter app on device, only a browser (web) client.
Bearer tokens (Sanctum personal access tokens) are the whole auth model;
there are no cookies, so `supports_credentials` stays `false` and Sanctum's
SPA/stateful cookie middleware (`statefulApi()`) is intentionally never
enabled.

`CORS_ALLOWED_ORIGINS` (`backend/config/cors.php`): comma-separated exact
origins. Outside `local`/`testing`, no loopback pattern is registered at
all — staging answers only its own web origin(s), production only its own.
`EnvironmentGuard` refuses to boot staging/production if this is empty or
contains a literal `*`; there is no silent "allow everything" fallback.
`allowed_methods` covers the current 244-route `/api/v1/*` method set (`GET`, `HEAD`, `POST`,
`DELETE`, `OPTIONS` — no route uses `PUT`/`PATCH`); `allowed_headers` is
just what `ApiClient` sends (`Authorization`, `Content-Type`, `Accept`) plus
`X-Requested-With`. `request_id` is already in the JSON body
(`meta.request_id`), so nothing is exposed via CORS headers.

CORS only applies to the `api/*`, `sanctum/csrf-cookie`, and
`broadcasting/auth` paths (`cors.php`'s `paths`). The framework's `/up`
health route and public media/storage URLs are deliberately outside that
list and unaffected by any of this.

`TRUSTED_PROXIES` (`bootstrap/app.php` + `App\Support\TrustedProxyList`):
empty by default, meaning `X-Forwarded-*` headers are ignored entirely —
correct for `php artisan serve` and any setup where Laravel receives
connections directly. Set it to a reverse proxy/load balancer's IP(s)
(comma-separated) or `*` (trust whichever host actually connected) only
once one actually sits in front of the app; then `X-Forwarded-Proto` /
`-Host` / `-For` are honored so `APP_URL` scheme/host detection stays
correct behind TLS-terminating infrastructure.

Trusted host (`Illuminate\Http\Middleware\TrustHosts`, enabled
unconditionally via `$middleware->trustHosts()`): derives its allowed
pattern straight from `APP_URL`'s own host plus subdomains — the same
public origin `EnvironmentGuard` already requires, so nothing extra to
configure. It already no-ops in `local`/`testing`
(`TrustHosts::shouldSpecifyTrustedHosts()`), so local dev is unaffected.

Reverb (P3-1) is a separate transport (Pusher-protocol websocket, its own
`/broadcasting/auth` handshake) and is not touched by any CORS change here;
its `REVERB_HOST`/scheme continue to come from the table above.

---

## Flutter dart-define

```bash
# Local REST (default debug; this machine's Laravel on :4000)
flutter run

# Explicit offline fixture; never permitted in a release build
flutter run --dart-define=USE_REST_API=false

# Local REST (this machine’s Laravel on :4000)
flutter run --dart-define=USE_REST_API=true \
  --dart-define=APP_ENV=local \
  --dart-define=REVERB_ENABLED=true \
  --dart-define=REVERB_APP_KEY=arucad-local-key \
  --dart-define=REVERB_HOST=localhost \
  --dart-define=REVERB_PORT=8080 \
  --dart-define=REVERB_SCHEME=http

# Staging release — its API URL is required; no localhost fallback
flutter build apk --dart-define=USE_REST_API=true \
  --dart-define=APP_ENV=staging \
  --dart-define=API_BASE_URL=https://staging-api.example.com/api/v1 \
  --dart-define=REVERB_ENABLED=true \
  --dart-define=REVERB_APP_KEY=staging-public-key \
  --dart-define=REVERB_HOST=staging-ws.example.com \
  --dart-define=REVERB_PORT=443 \
  --dart-define=REVERB_SCHEME=https \
  --dart-define=SENTRY_DSN=

flutter build apk --dart-define=USE_REST_API=true \
  --dart-define=APP_ENV=production \
  --dart-define=API_BASE_URL=https://api-aruverse.arucad.edu.tr/api/v1 \
  --dart-define=REVERB_ENABLED=true \
  --dart-define=REVERB_APP_KEY=prod-public-key \
  --dart-define=REVERB_HOST=ws.example.com \
  --dart-define=REVERB_PORT=443 \
  --dart-define=REVERB_SCHEME=https \
  --dart-define=SENTRY_DSN=
```

Release builds always use REST and `USE_REST_API=false` is rejected. Production
defaults to `https://api-aruverse.arucad.edu.tr/api/v1`; an explicit
`API_BASE_URL` can replace it, but loopback targets are rejected. Staging still
requires an explicit public URL, so a store build cannot ship as mock or point
at a developer machine.

Use the matching `google-services.json` / `GoogleService-Info.plist` /
`firebase_options.dart` per environment; those files stay gitignored.

`SENTRY_DSN` (P3-6): empty in local dev — `SentryFlutter.init()` still runs
the app normally, it just never reports anything
(`SentryBootstrapOptions.isEnabled`). Read directly via
`String.fromEnvironment('SENTRY_DSN')` at the very top of `main()`, before
`AppConfig`/Firebase, so `SentryFlutter.init(..., appRunner: ...)` can wrap
the whole app and catch bootstrap-time errors too — its `appRunner` installs
`FlutterError.onError`, `PlatformDispatcher.instance.onError` and a
`runZonedGuarded` zone internally, so nothing else in the app adds its own
global handler (would double/triple-report every crash). Optional
`SENTRY_TRACES_SAMPLE_RATE` (0.0–1.0) stays unset/null by default — no
performance tracing in this milestone, same as the backend.

---

## Out of scope here

Production deploy, Postgres data migration, full Sentry Performance tracing,
TLS certificate provisioning, DNS, CDN, WAF, rate-limiting redesign, store
signing, Firebase project creation, Reverb production host setup.
