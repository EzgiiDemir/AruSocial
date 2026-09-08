# Production Deployment (P3-12)

This document is the **procedure**, not a completed deployment. No real
hosting provider, PostgreSQL server, or process manager has been provisioned
from this repository — see `docs/EXTERNAL_ACCOUNTS.md` for what ARUCAD needs
to supply before any of this can actually run in production. Nothing here
invents a provider account, server, or credential.

---

## 1. Provider

No provider is currently selected — this repo does not depend on one
(`config/*.php` is 12-factor: everything comes from `.env`). Reasonable
managed-Postgres + PHP-hosting candidates for a small Laravel + Reverb app
(unordered, not a recommendation of one over another — ARUCAD/whoever owns
billing decides):

| Candidate | Why it could fit | Note |
|---|---|---|
| Railway | One-click Postgres + PHP + long-running worker/Reverb process, generous free tier for staging | No account created here |
| Render | Managed Postgres, background workers, web services | No account created here |
| Fly.io | Runs a long-lived Reverb process well (not serverless-only), Postgres add-on | No account created here |
| DigitalOcean / Hetzner VPS + managed Postgres | Full control over `php artisan reverb:start` as a systemd service | Most manual, cheapest at small scale |
| A university-owned server | If ARUCAD IT already has infrastructure | Unknown to this repo |

`NOT_DEPLOYED` — this section is a menu, not a decision.

---

## 2. Application environment (production)

Copy the production block from `backend/.env.example` onto the real host's
`.env` and fill every value — do not commit it. `EnvironmentGuard`
(`app/Support/EnvironmentGuard.php`) refuses to boot if any of these still
look like local defaults (SQLite, `APP_DEBUG=true`, localhost `APP_URL` /
`REVERB_HOST`, `QUEUE_CONNECTION=sync`, empty/wildcard/localhost
`CORS_ALLOWED_ORIGINS`, `MAIL_MAILER` other than `smtp`, or a partial
Firebase credential set) — there is no silent fallback to insecure or
non-functional config.

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.example.com          # real production host
APP_KEY=                                  # php artisan key:generate --show, paste once

DB_CONNECTION=pgsql
DB_HOST=                                  # real Postgres host
DB_PORT=5432
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=
DB_SSLMODE=require                        # prefer only on a trusted private network

QUEUE_CONNECTION=database                 # or redis if the provider gives one
CACHE_STORE=database                      # or redis

BROADCAST_CONNECTION=reverb
REVERB_APP_ID=
REVERB_APP_KEY=
REVERB_APP_SECRET=
REVERB_HOST=ws.example.com
REVERB_PORT=443
REVERB_SCHEME=https

MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=587
MAIL_ENCRYPTION=tls
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_ADDRESS=
MAIL_FROM_NAME=

SENTRY_DSN=                               # real backend DSN (P3-11)

# Optional Mega-2 providers (empty = honest 501, never fake)
# ROUTING_BASE_URL=https://routing.example.com   # OSRM-compatible base; Flutter never holds this
# GROQ_API_KEY=                                  # vision poster → draft; also Ask ARUCAD text
# GROQ_VISION_MODEL=meta-llama/llama-4-scout-17b-16e-instruct

FIREBASE_PROJECT_ID=
FIREBASE_CLIENT_EMAIL=
FIREBASE_PRIVATE_KEY=
FCM_REQUIRED=true

CORS_ALLOWED_ORIGINS=https://app.example.com
TRUSTED_PROXIES=                          # the reverse proxy's IP(s), or "*" if it's the only way in
```

`AUTH_ALLOWED_EMAIL_DOMAIN` stays `@arucad.edu.tr` unless ARUCAD says
otherwise — unrelated to this milestone.

---

## 3. Build / cache procedure

Verified locally against this exact codebase (Laravel 13.17, PHP 8.3,
244 `/api/v1/*` routes — all controller-based, **no route closures**, so
`route:cache` is safe here and was confirmed working: `php artisan
route:cache` → `route:list` still resolves all routes → `php artisan test`
still green → `route:clear`). Current API route count is **244**
(see `docs/API_CONTRACT.md`):

```bash
composer install --no-dev --optimize-autoloader
cp .env.example .env    # then fill in the real production values (§2)
php artisan key:generate --show   # only if APP_KEY is still empty; keep the value in .env, not in shell history
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan migrate --force
php artisan storage:link   # only if it doesn't already exist (media library public disk)
```

Never `php artisan migrate:fresh` against production — it drops every
table. `migrate --force` only runs pending migrations, matching the
existing `database/migrations/*` set unchanged by this milestone.

After deploying new code, `config:cache`/`route:cache`/`view:cache` must be
re-run (they cache the *old* code's config/routes otherwise) — this is a
build-time step, not one-time setup.

### Android release build

The APK never displays an IP/port prompt to a student. Its public HTTPS API
address is compiled in once by `deploy/scripts/build-android.ps1`; the script
refuses a LAN or loopback URL, so an APK cannot accidentally ship pointing at
one developer machine. Release builds also disable Android cleartext HTTP;
only a debug build permits a local `php artisan serve` address.
The script discovers Flutter from `PATH`, `FLUTTER_ROOT`, or common local SDK
locations and uses Android Studio's bundled JDK when `JAVA_HOME` is unset; the
only build-time inputs that cannot be guessed are ARUCAD's real public service
addresses and signing credentials.

```powershell
$env:API_BASE_URL = 'https://api.example.edu/api/v1'
$env:REVERB_HOST = 'ws.example.edu'
$env:REVERB_APP_KEY = 'public-reverb-key'
.\deploy\scripts\build-android.ps1 -Environment production -Format appbundle
```

This needs a deployed API with a stable HTTPS DNS name. A physical phone cannot
reliably discover a laptop's changing Wi-Fi IP after installation; embedding a
LAN address or prompting each student for one is not a production solution.

### Public release acceptance check

After DNS, TLS and the services are deployed, run the verifier from the
repository root. It checks the public API TLS socket plus JSON responses for
the student event/place collections; optional arguments also check the Reverb
port and a real OSRM-compatible route. It exits non-zero on the first failed
check, making it suitable for CI or a release checklist.

```powershell
.\deploy\scripts\verify-public-release.ps1 `
  -ApiBaseUrl 'https://api.example.edu/api/v1' `
  -ReverbHost 'ws.example.edu' `
  -OsrmBaseUrl 'https://routing.example.edu'
```

### Local test accounts

`php artisan migrate:fresh --seed` creates these accounts in `local` and
`testing` only (never staging/production):

| Portal | E-mail | Password |
|---|---|---|
| Student | `student@arucad.edu.tr` | `password` |
| Trainer | `trainer@arucad.edu.tr` | `password` |
| Admin | `admin@arucad.edu.tr` | `password` |

---

## 4. Queue worker

`QUEUE_CONNECTION=database` is required in staging/production
(`EnvironmentGuard`). Two things are queued today:

- `App\Jobs\DeliverFcmNotification` (P3-2) — one job per push notification.

A long-running worker process is required or queued jobs simply never
run (they'd sit in the `jobs` table forever, and FCM notifications would
never actually reach a device even though the inbox row is written):

```bash
php artisan queue:work --queue=default --tries=3 --backoff=10 --max-time=3600
```

Run under a process manager that restarts it on crash and on deploy
(systemd unit, Supervisor, or the hosting provider's own "worker" process
type — Railway/Render both have one). `--max-time` recycles the worker
periodically so a long-lived PHP process can't slowly leak memory forever.
`php artisan queue:restart` after every deploy so workers pick up new code
(they otherwise keep running the old code in memory).

---

## 5. Reverb (websocket) process

`php artisan reverb:start --host=0.0.0.0 --port=8080` (or whatever
`REVERB_SERVER_HOST`/`REVERB_SERVER_PORT` are set to) must run as its own
long-lived process, separate from the PHP-FPM/web process — it's a
persistent TCP server, not a request handler. Same process-manager
requirement as the queue worker (systemd/Supervisor/provider "worker"
type). The public-facing `REVERB_HOST`/`REVERB_SCHEME=https`/`REVERB_PORT`
in `.env` (§2) are what Flutter/browser clients connect *to*; if Reverb
sits behind a reverse proxy (nginx/Caddy terminating TLS and proxying to
the internal `reverb:start` port), that proxy needs standard WebSocket
upgrade headers (`Upgrade`, `Connection`) forwarded, and `TRUSTED_PROXIES`
(§2, `app/Support/TrustedProxyList.php`) set to that proxy's address so
`X-Forwarded-Proto`/`-Host` resolve correctly for the rest of the app.

No real process manager/provider is configured from this repo — this is
the plan, not a running service. `NOT_DEPLOYED`.

---

## 6. Backup strategy (PostgreSQL)

No real backup provider/account exists yet — this is the procedure to put
in place once a real Postgres host does.

- **Daily automated backup**: `pg_dump --format=custom` (or the managed
  provider's built-in daily snapshot, if it has one — most managed
  Postgres offerings do and that's the simpler default) to storage outside
  the database server itself (object storage / the provider's backup
  storage), retained **≥ 14 days** minimum, **30 days** preferred.
- **Pre-deploy backup**: an explicit `pg_dump` immediately before every
  `php artisan migrate --force` in the deploy procedure (§8) — cheap
  insurance against a bad migration, independent of the daily schedule.
- **Restore test**: quarterly (or before any major schema change), restore
  the latest backup into a scratch database and run
  `php artisan migrate:status` + a manual smoke of a few endpoints against
  it — an untested backup is not a backup.
- **Retention**: daily backups for 14–30 days, plus at least one
  pre-deploy backup kept until the next successful deploy is confirmed
  healthy.

`NOT_DEPLOYED` — no backup schedule is actually running anywhere yet.

---

## 7. HA / replication

Requirements for a real production Postgres, if/when uptime needs it:

- **Read replica** (streaming replication) if read load ever needs to be
  offloaded — not needed at current scale, but the app makes no
  primary/replica assumptions that would block adding one later (no
  session-affinity reads-after-write logic that would break against a
  lagging replica, since everything currently reads from the same
  connection it wrote with).
- **Failover**: most managed Postgres providers (RDS, Cloud SQL,
  Railway/Render's managed Postgres) offer automatic failover to a
  standby — prefer that over self-managed failover tooling (Patroni etc.)
  unless self-hosting is the chosen path (§1).
- **Connection pooling**: `pgbouncer` (or the provider's built-in pooler)
  once concurrent PHP-FPM/worker connections approach the server's
  `max_connections` — not needed at current scale, but the app has no
  persistent-connection assumptions that would conflict with a transaction
  or session pooler.

`NOT_DEPLOYED` — no HA is configured; this is a requirements list for
whenever real infrastructure exists. No fake HA setup was created.

---

## 8. Rollback procedure

```text
1. pg_dump backup (§6, pre-deploy)                 — always, before touching anything
2. deploy new code (git pull / container image)
3. composer install --no-dev --optimize-autoloader
4. php artisan config:cache && route:cache && view:cache
5. php artisan migrate --force
6. smoke test (§9 below)
7a. smoke passes  → php artisan queue:restart, done
7b. smoke fails   → roll back code to the previous release
                    (previous git tag / container image)
                  → re-run config:cache/route:cache/view:cache for the
                    OLD code
                  → if step 5's migration is the suspected cause and it
                    has a safe, reviewed `down()`: php artisan migrate:rollback
                    for exactly that migration — otherwise restore the
                    step-1 backup instead of guessing with `down()`
                  → php artisan queue:restart
```

`down()` methods on the newer migrations (`convert_social_graph_to_user_ids`,
`convert_chat_messages_to_conversations`, `convert_post_comment_authors_to_user_ids`,
`convert_review_authors_to_user_ids`, `convert_feed_and_story_author_ids_to_user_fk`,
etc.) exist for local development convenience (`migrate:fresh` round-trips
cleanly) — they are **not** a verified production rollback path, since a
`down()` written months earlier was never tested against real production
data shaped by everything that happened in between. The safe rollback for
data-shape migrations is restoring the pre-deploy backup (§6, step 1
above), not blindly running `down()`.

### 9. Post-deploy smoke checklist

```
GET  /up                          framework health
GET  /api/v1/health               DB reachability
POST /api/v1/auth/session         real login
GET  /api/v1/me                   token round-trip
GET  /api/v1/feed                 paginated read
GET  /api/v1/food-venues          read
GET  /api/v1/media                read
GET  /api/v1/admin/audit-log      admin auth + pagination
GET  /api/v1/notifications        read
POST /api/v1/chat/{peer}/messages + Reverb event received by a second session
POST /push-tokens                 registration
```

Unauthorized checks: no token → 401 `AUTH_REQUIRED`; wrong role on an
`/admin/*` route → 403 `FORBIDDEN`.

---

## Summary status (this milestone)

| Item | Status |
|---|---|
| Provider selection | `NOT_DEPLOYED` (candidates only, §1) |
| Production `.env` template | Ready (`backend/.env.example`, §2) |
| Build/cache procedure | Verified against this codebase (§3) |
| Queue worker plan | Documented, `NOT_DEPLOYED` (§4) |
| Reverb process plan | Documented, `NOT_DEPLOYED` (§5) |
| Backup strategy | Documented, `NOT_DEPLOYED` (§6) |
| HA/replication | Requirements only, `NOT_DEPLOYED` (§7) |
| Rollback procedure | Documented (§8) |

See `docs/EXTERNAL_ACCOUNTS.md` for exactly what ARUCAD needs to provide
before any `NOT_DEPLOYED` line above can change.
