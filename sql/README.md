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

- **`schema.sql`** — a real, generated reference dump of the current
  table definitions (`CREATE TABLE ...` statements pulled straight from
  `sqlite_master`, not hand-written) — read this if you just want to see
  the schema without running anything. It goes stale the moment a
  migration changes; regenerate it with:

  ```bash
  cd backend
  php artisan tinker --execute="
    \$rows = DB::select(\"SELECT sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name\");
    file_put_contents('../sql/schema.sql', implode(\"\n\n\", array_map(fn(\$r) => \$r->sql . ';', \$rows)));
  "
  ```

## Why the actual schema *definitions* still live in `backend/`

The migrations themselves (`backend/database/migrations/*.php`) are code,
not SQL — Laravel's `php artisan migrate` only looks in that folder, and
moving them elsewhere would break the framework's own tooling for no real
benefit. `schema.sql` above is a mirror for reading, not the source of
truth; `backend/database/migrations/` is the source of truth.

## PostgreSQL

This is SQLite because no PostgreSQL server is available in this
environment (see `docs/EKSIKLER.md`). Nothing about this folder assumes
SQLite specifically — when a real Postgres instance exists, point
`backend/.env`'s `DB_CONNECTION`/`DB_HOST`/`DB_DATABASE` at it and run
`php artisan migrate --seed` there instead. This folder's `database.sqlite`
just stops being used at that point; nothing here needs to move.
