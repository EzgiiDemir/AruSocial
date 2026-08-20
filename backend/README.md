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
```

Health check: `GET http://localhost:4000/up` (Laravel's built-in health route).

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
# Android emulator (10.0.2.2 is the emulator's alias for the host machine)
flutter run --dart-define=USE_REST_API=true --dart-define=API_BASE_URL=http://10.0.2.2:4000/api/v1

# Web / desktop (same machine as the backend)
flutter run -d web-server --dart-define=USE_REST_API=true --dart-define=API_BASE_URL=http://localhost:4000/api/v1

# A real phone on the same Wi-Fi as this machine — use this machine's LAN IP instead of localhost
flutter run --dart-define=USE_REST_API=true --dart-define=API_BASE_URL=http://<this-machine-LAN-IP>:4000/api/v1
```

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

Local dev uses SQLite (`database/database.sqlite`, zero extra setup) —
Laravel's own default. The migrations in `database/migrations/` are
written with Eloquent's schema builder only (no raw SQLite-specific SQL),
so switching the production datasource to PostgreSQL is a `.env` change,
not a schema rewrite:

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=arucad
DB_USERNAME=arucad
DB_PASSWORD=...
```

then `php artisan migrate --seed` against that real Postgres instance. The
`pdo_pgsql`/`pgsql` PHP extensions are already enabled in this environment
— only a real, reachable PostgreSQL *server* is missing here (no package
manager available in this sandbox to install one, and provisioning a real
production database is ARUCAD's own infrastructure decision — see
`docs/GERCEK_PROJEYE_GECIS.md`).

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

No real token validation yet — every request is treated as the single
seeded demo account (Laravel Sanctum is installed and ready, but nothing
issues or checks a real token). Real per-user auth needs a real Microsoft
Entra tenant (only ARUCAD's own IT admin can create the App Registration)
plus the backend validating that token and mapping Entra groups to
`UserRole` — see the doc for the exact steps.
