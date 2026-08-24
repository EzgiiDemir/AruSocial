# AruSocial backend (Laravel)

A real, running Laravel 13 (PHP) REST API backed by a real database
(SQLite for local dev — see below for the PostgreSQL path). It implements
the exact contract `lib/core/services/rest_campus_repository.dart` already
expects — see `docs/API_CONTRACT.md` and `docs/GERCEK_PROJEYE_GECIS.md` for
scope, what's covered, and what isn't yet. (An earlier version of this
backend was written in Node.js/Express — it was fully replaced with this
Laravel implementation; the wire contract, and therefore the Flutter side,
did not change at all.)

## Run it

```bash
cd backend
composer install
php artisan migrate:fresh --seed   # creates + seeds database/database.sqlite
php artisan serve --port=4000
php artisan reverb:start
```

Health check: `GET http://localhost:4000/api/v1/health` — answers on the same
`/api/v1` base path the Flutter app is configured with, so it verifies the
exact URL the app uses (`GET /api/v1` itself answers identically). Reports
whether the database is reachable too:

```json
{"data":{"status":"ok","service":"arucad-campus-api","version":"v1","database":"ok"},
 "meta":{"request_id":"req-…"},"error":null}
```

Laravel's own built-in `GET http://localhost:4000/up` still works and checks
the framework only.

### If Composer/PHP complain about missing `fileinfo`/`pdo_sqlite`/`sqlite3`

Those extensions ship with PHP but aren't always enabled in `php.ini` by
default, and editing the system `php.ini` under `C:\Program Files\PHP\...`
needs admin rights. Instead, point `PHPRC` at a local copy with them turned
on (this repo's `.phpconfig/` does exactly that, gitignored since it's
machine-specific — recreate it if missing):

```bash
mkdir .phpconfig
cp "C:/Program Files/PHP/current/php.ini" .phpconfig/php.ini
# then uncomment (remove the leading ";") these three lines in .phpconfig/php.ini:
#   extension=fileinfo
#   extension=pdo_sqlite
#   extension=sqlite3
PHPRC=".phpconfig" composer install
PHPRC=".phpconfig" php artisan migrate:fresh --seed
PHPRC=".phpconfig" php artisan serve --port=4000
```

## Point the Flutter app at it

Off by default — a plain `flutter run` still uses `MockCampusRepository`
with zero setup. To use this real backend instead:

```bash
# Web / desktop / Android emulator — port 4000 on this machine is the
# default, and each platform resolves it correctly on its own
flutter run -d chrome --dart-define=USE_REST_API=true \
  --dart-define=REVERB_APP_KEY=arucad-local-key \
  --dart-define=REVERB_HOST=localhost \
  --dart-define=REVERB_PORT=8080 \
  --dart-define=REVERB_SCHEME=http
flutter run -d emulator-5554 --dart-define=USE_REST_API=true

# A real phone on the same Wi-Fi can't reach this machine's loopback, so
# it needs the LAN IP explicitly (and `php artisan serve --host=0.0.0.0`)
flutter run --dart-define=USE_REST_API=true --dart-define=API_BASE_URL=http://<this-machine-LAN-IP>:4000/api/v1
```

`API_BASE_URL` always wins when given. Without it the default is
`http://localhost:4000/api/v1`, except on Android where it's
`http://10.0.2.2:4000/api/v1` — the emulator's own loopback is the
emulator, and 10.0.2.2 is the alias it routes back to the host.

## Verify it end-to-end without a browser or emulator

```bash
# with the server already running:
dart run tool/verify_rest_backend.dart
```

Exercises every `CampusRepository` method for real (reads + writes: join an
event, like/comment/report a post, admin upsert/delete an event) and fails
loudly if anything doesn't round-trip correctly. This script talks to
whatever `API_BASE_URL` you pass it — it doesn't know or care that the
backend is now Laravel instead of Node, which is the point of keeping the
wire contract stable across the rewrite.

## SQLite now, PostgreSQL for production

Local dev uses SQLite (`../sql/database.sqlite` via `DB_DATABASE`, zero extra
setup). The same Laravel migrations run on PostgreSQL. Default `.env` stays
SQLite; switching a *reachable* Postgres is an explicit `.env` change, not a
schema rewrite. Production cutover is not done in this repo.

Local vs staging vs production `.env` boundaries (no deploy):
`docs/ENVIRONMENTS.md`. Staging/production boot refuses SQLite, debug,
and localhost Reverb/APP_URL.

```env
# default local (keep this)
DB_CONNECTION=sqlite
DB_DATABASE=../sql/database.sqlite

# optional PostgreSQL
# DB_CONNECTION=pgsql
# DB_HOST=127.0.0.1
# DB_PORT=5432
# DB_DATABASE=arucad
# DB_USERNAME=arucad
# DB_PASSWORD=
```

then `php artisan migrate --seed` against that Postgres instance.

SQLite tests (default, what `php artisan test` runs):

```bash
php artisan test
```

PostgreSQL tests (needs a reachable server; CI starts `postgres:16`):

```bash
php artisan test --configuration=phpunit.pgsql.xml
```

`phpunit.pgsql.xml` uses `arucad` / `arucad` / `arucad_test` on `127.0.0.1:5432`.
The `pdo_pgsql` PHP extension must be enabled. Do not point the default
phpunit.xml at Postgres — local/CI SQLite must keep working.

## What this covers

Every method on `CampusRepository` (`lib/core/services/contracts.dart`):
profile, places + reviews + reports, events + join, quests, social feed +
likes + comments + reports, stories, leaderboard, check-ins, activity log,
an AI proxy (real if `GROQ_API_KEY` is set in `backend/.env`, otherwise it
says so instead of faking an answer), and the admin events/reports
endpoints.

## What this does NOT cover

Everything that currently lives in a separate local `SharedPreferences`
store instead of `CampusRepository` — clubs, sports, services, food
venues, directory, admin pages, media library, chat, follow/block, saved
posts, role assignments, audit log, content revisions. See
`docs/GERCEK_PROJEYE_GECIS.md` for the full, honest breakdown.

## Auth

Every route under `/api/v1` requires a real Sanctum bearer token, except
the two diagnostics endpoints (`/api/v1`, `/api/v1/health`) and the one
that hands a token out:

```
POST /api/v1/auth/session   {"email": "...", "password": "..."}
  → {"data": {"user": {...}, "token": "..."}, "meta": {...}, "error": null}

POST /api/v1/auth/logout    (Authorization: Bearer ...)
  → revokes exactly the token it was called with
```

Sign-in is restricted to `AUTH_ALLOWED_EMAIL_DOMAIN` (default
`@arucad.edu.tr`) and the password is really checked against the stored
hash. There's no student directory to pre-provision accounts from, so a
first sign-in registers the account with the password given — every later
sign-in has to match it. A request with no token, or a revoked one, gets a
`401` with `error.code = AUTH_REQUIRED`.

The authenticated user is resolved per request from that token
(`request()->user()`, via `ApiResponds::currentUser()`), so two people on
the same backend are now genuinely two accounts.

## Authorization

Being signed in is not being an admin. Every `/admin/*` route (plus the
media library and content revisions, which only the Admin Panel calls)
names the permission it requires, and a signed-in student holds none of
them:

```
no token        → 401 AUTH_REQUIRED
wrong person    → 403 FORBIDDEN
banned account  → 403 ACCOUNT_BANNED
```

Permissions come from `App\Services\GranularPermissions`, which is the one
place the rules live:

- each admin section has its own key (`events.manage`, `moderation.moderate`,
  `users.manage`, …), used directly as the route's middleware argument;
- each key belongs to a capability bucket, and each product role covers
  certain buckets — the same three capabilities `UserRole` already
  expresses in the Flutter client, kept identical on purpose;
- `superAdmin` passes everything, as one explicit central rule;
- `role_assignments.permissions` (nullable JSON) grants one person extra
  keys on top of their role. Additive only: it can never take away access
  the role itself grants.

`role_assignments` is the single source of truth for authorization.
`users.role` is a profile display field and grants nothing — `GET /me`
reports the role that is actually enforced.

A ban applies to the account, not to a URL: there is no `/admin/*`
exemption.

## Likes

A like is a row in `post_likes`, keyed `(user_id, post_id)` with a unique
index — so one account can like a post once, and the database is what
enforces it rather than a controller checking first.

`feed_posts` has no `liked_by_me` or `likes` column any more. Both were
answers to per-user questions stored on the post itself: `liked_by_me` made
one person's like show for everyone, and a counter next to the rows it
counts is a second answer waiting to disagree with the first. The API keeps
both field names:

```
likes      = post_likes for this post, counted
likedByMe  = does a post_likes row exist for this post and the caller
```

so two accounts reading the same post get the same `likes` and their own
`likedByMe`. `POST /feed/{id}/like` toggles the caller's own row and can
only ever touch that row.

**Not yet:** Microsoft Entra as a production identity provider. It needs a
real tenant (only ARUCAD's IT admin can create the App Registration) plus
backend validation of its tokens and a mapping from Entra groups to
`UserRole` — see `docs/GERCEK_PROJEYE_GECIS.md`.
