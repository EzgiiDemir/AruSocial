# sql/

The actual database for local development, kept separate from the
application code in `backend/` so "where the data lives" and "what runs
the app" are two different, clearly-labeled things.

- **`database.sqlite`** — the real, live SQLite database `backend/`
  reads and writes (wired via `DB_DATABASE=../sql/database.sqlite` in
  `backend/.env`). **Not committed to git** — it's runtime data, not
  source. Recreate it any time with:

  ```bash
  cd backend
  php artisan migrate:fresh --seed
  ```

- **`schema.sql`** — a generated snapshot of the current Laravel
  migration state (`CREATE TABLE` / index SQL from `sqlite_master`). It
  is documentation / a readable mirror, not the source of truth and not
  a dump of live `database.sqlite` contents. It goes stale the moment a
  migration changes.

  Regenerating **must not** use the live database. Migrate a throwaway
  SQLite file, then dump:

  ```bash
  cd backend
  php scripts/dump_schema.php
  ```

  Table order in the dump is alphabetical (`sqlite_master ORDER BY name`),
  not topological. Import with `PRAGMA foreign_keys = OFF` first, then
  turn foreign keys on. Do not put seed `INSERT`s in this file.

## Why the actual schema *definitions* still live in `backend/`

The migrations themselves (`backend/database/migrations/*.php`) are code,
not SQL — Laravel's `php artisan migrate` only looks in that folder, and
moving them elsewhere would break the framework's own tooling for no real
benefit. `schema.sql` above is a mirror for reading, not the source of
truth; `backend/database/migrations/` is the source of truth.

## PostgreSQL

`schema.sql` is a **SQLite** `sqlite_master` snapshot. Do not convert it
into a PostgreSQL dump; Laravel migrations are the source of truth for
both drivers (`php artisan migrate` on SQLite or `pgsql`).

Local default remains SQLite (`database.sqlite`). When a real Postgres
instance exists, point `backend/.env` at it (`DB_CONNECTION=pgsql` plus
host/port/database/user/password) and run `php artisan migrate --seed`
there. That is opt-in; it is not a production cutover. Feature tests on
Postgres: `php artisan test --configuration=phpunit.pgsql.xml` (CI starts
a `postgres:16` service). This folder's `database.sqlite` just stops being
used for that process; nothing here needs to move.
