<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P3-8 §8.3 — one-time SQLite → PostgreSQL data copy for cutover.
 *
 * This is deliberately NOT a Laravel migration: migrations define schema,
 * this moves rows between two already-migrated databases (same `php artisan
 * migrate --force` must already have run against the PostgreSQL connection
 * before this command is useful — it never creates/alters tables).
 *
 * Table order is derived at runtime from each table's own foreign keys
 * (topological sort), so this never hardcodes — and can never silently drift
 * from — the migration set's actual dependency graph.
 *
 * Safety:
 *  - --dry-run: report row counts per table, write nothing.
 *  - --confirm-source-backup is required for a real (non-dry-run) run — this
 *    command refuses to move a single row otherwise. It does not take a
 *    backup itself (that depends on the real hosting provider / P3-12).
 *  - Idempotent: every insert is `insertOrIgnore` keyed on the source
 *    primary key, so re-running after a partial failure only fills in the
 *    rows that are still missing — it never updates or deletes a row that
 *    already exists on the target.
 *  - Transaction-per-chunk on the target connection: a failure partway
 *    through a table rolls back that chunk, not the whole run.
 *  - Never touches the source (SQLite) connection — read-only throughout.
 *  - Never runs `migrate:fresh` / truncates / deletes anything on the
 *    target. If the target already has conflicting data, insertOrIgnore
 *    just skips those rows (report explicitly shows the diff below).
 */
class MigrateSqliteToPgsql extends Command
{
    protected $signature = 'db:migrate-sqlite-to-pgsql
        {--source=sqlite : Source Laravel DB connection name (must be SQLite)}
        {--target=pgsql : Target Laravel DB connection name (must be PostgreSQL)}
        {--dry-run : Report row counts only, write nothing}
        {--confirm-source-backup : Required to actually write; asserts a source backup was taken first}
        {--chunk=500 : Rows per insert batch}
        {--only= : Comma-separated table allowlist (for a partial/retry run)}';

    protected $description = 'P3-8: copy existing rows from a migrated SQLite database into an already-migrated PostgreSQL database (idempotent, dry-run capable).';

    /** Framework/internal tables that are never application data. */
    private const SKIP_TABLES = [
        'migrations', 'cache', 'cache_locks', 'jobs', 'job_batches',
        'failed_jobs', 'sessions', 'password_reset_tokens',
    ];

    /** `main.users` / `public.users` -> `users`. */
    private function bareTableName(string $table): string
    {
        $dot = strrpos($table, '.');

        return $dot === false ? $table : substr($table, $dot + 1);
    }

    public function handle(): int
    {
        $sourceName = (string) $this->option('source');
        $targetName = (string) $this->option('target');
        $dryRun = (bool) $this->option('dry-run');
        $chunkSize = max(1, (int) $this->option('chunk'));

        $sourceDriver = config("database.connections.{$sourceName}.driver");
        $targetDriver = config("database.connections.{$targetName}.driver");

        if ($sourceDriver !== 'sqlite') {
            $this->error("--source={$sourceName} is not a sqlite connection (driver={$sourceDriver}).");

            return self::FAILURE;
        }

        if ($targetDriver !== 'pgsql') {
            $this->error("--target={$targetName} is not a pgsql connection (driver={$targetDriver}).");

            return self::FAILURE;
        }

        if (! $dryRun && ! $this->option('confirm-source-backup')) {
            $this->error('Refusing to write: re-run with --confirm-source-backup once a verified backup of the SQLite source exists (or use --dry-run first).');

            return self::FAILURE;
        }

        try {
            $source = DB::connection($sourceName);
            $target = DB::connection($targetName);
            $source->getPdo();
            $target->getPdo();
        } catch (\Throwable $e) {
            $this->error('Could not connect to both connections: '.$e->getMessage());

            return self::FAILURE;
        }

        // getTableListing() returns schema-qualified names — `main.users` on
        // SQLite, `public.users` on PostgreSQL. Comparing those raw makes
        // every table look missing on the target, and the skip-list stops
        // matching too. Strip the schema on both sides before any comparison.
        $allTables = collect(Schema::connection($sourceName)->getTableListing())
            ->map(fn (string $t) => $this->bareTableName($t))
            ->reject(fn (string $t) => in_array($t, self::SKIP_TABLES, true))
            ->values();

        $only = array_filter(array_map('trim', explode(',', (string) $this->option('only'))));
        if ($only !== []) {
            $allTables = $allTables->intersect($only)->values();
        }

        $targetTables = collect(Schema::connection($targetName)->getTableListing())
            ->map(fn (string $t) => $this->bareTableName($t));
        $missingOnTarget = $allTables->diff($targetTables);
        if ($missingOnTarget->isNotEmpty()) {
            $this->error('These tables exist in the source but not on the target — run `php artisan migrate --force` against the target connection first: '.$missingOnTarget->implode(', '));

            return self::FAILURE;
        }

        $order = $this->topologicalOrder($sourceName, $allTables->all());

        $this->info(($dryRun ? '[DRY RUN] ' : '').'Copying '.count($order)." tables from [{$sourceName}] to [{$targetName}] in dependency order:");
        $this->line('  '.implode(' -> ', $order));
        $this->newLine();

        $summary = [];

        foreach ($order as $table) {
            $summary[$table] = $this->copyTable($source, $target, $table, $chunkSize, $dryRun);
        }

        if (! $dryRun) {
            $this->resetPostgresSequences($target, $order);
        }

        $this->newLine();
        $this->table(
            ['Table', 'Source rows', ($dryRun ? 'Would insert' : 'Inserted'), 'Already on target (skipped)'],
            collect($summary)->map(fn (array $s, string $t) => [$t, $s['source'], $s['inserted'], $s['skipped']])->values()
        );

        if ($dryRun) {
            $this->comment('Dry run only — nothing was written. Re-run with --confirm-source-backup to apply.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $tables
     * @return array<int, string>
     */
    private function topologicalOrder(string $sourceName, array $tables): array
    {
        $tableSet = array_flip($tables);
        $deps = [];

        foreach ($tables as $table) {
            $deps[$table] = [];
            foreach (Schema::connection($sourceName)->getForeignKeys($table) as $fk) {
                $referenced = $fk['foreign_table'] ?? null;
                if ($referenced !== null && $referenced !== $table && isset($tableSet[$referenced])) {
                    $deps[$table][] = $referenced;
                }
            }
        }

        $ordered = [];
        $visited = [];
        $visiting = [];

        $visit = function (string $table) use (&$visit, &$ordered, &$visited, &$visiting, $deps): void {
            if (isset($visited[$table])) {
                return;
            }
            if (isset($visiting[$table])) {
                // Circular FK (shouldn't happen in this schema) — break the
                // cycle rather than infinite-loop; insertOrIgnore below
                // tolerates a temporarily-missing parent on retry.
                return;
            }
            $visiting[$table] = true;
            foreach ($deps[$table] as $dep) {
                $visit($dep);
            }
            unset($visiting[$table]);
            $visited[$table] = true;
            $ordered[] = $table;
        };

        foreach ($tables as $table) {
            $visit($table);
        }

        return $ordered;
    }

    /**
     * @return array{source: int, inserted: int, skipped: int}
     */
    private function copyTable($source, $target, string $table, int $chunkSize, bool $dryRun): array
    {
        $sourceCount = $source->table($table)->count();
        $inserted = 0;
        $skipped = 0;

        if ($sourceCount === 0) {
            $this->line("  {$table}: empty, skipping");

            return ['source' => 0, 'inserted' => 0, 'skipped' => 0];
        }

        $primaryKey = $this->primaryKeyFor($table);
        $bar = $this->output->createProgressBar($sourceCount);
        $bar->setMessage($table);

        $query = $source->table($table);
        if ($primaryKey !== null) {
            $query->orderBy($primaryKey);
        }

        $query->chunk($chunkSize, function ($rows) use ($target, $table, $primaryKey, $dryRun, &$inserted, &$skipped, $bar) {
            $rows = $rows->map(fn ($row) => (array) $row)->all();

            if ($dryRun) {
                if ($primaryKey !== null) {
                    $ids = array_column($rows, $primaryKey);
                    $existing = $target->table($table)->whereIn($primaryKey, $ids)->pluck($primaryKey)->all();
                    $skipped += count($existing);
                    $inserted += count($rows) - count($existing);
                } else {
                    $inserted += count($rows);
                }
                $bar->advance(count($rows));

                return;
            }

            $target->transaction(function () use ($target, $table, $rows, &$inserted, &$skipped) {
                foreach ($rows as $row) {
                    $ok = $target->table($table)->insertOrIgnore($row);
                    if ($ok) {
                        $inserted++;
                    } else {
                        $skipped++;
                    }
                }
            });
            $bar->advance(count($rows));
        });

        $bar->finish();
        $this->newLine();

        return ['source' => $sourceCount, 'inserted' => $inserted, 'skipped' => $skipped];
    }

    private function primaryKeyFor(string $table): ?string
    {
        // Every migration in this schema uses either `id` (auto-increment)
        // or a string primary key also named `id` — see sql/schema.sql /
        // SchemaInventoryTest. Falls back to null (no idempotency key) only
        // if a table genuinely has neither, which insertOrIgnore still
        // tolerates (just can't dedupe as precisely on retry).
        return Schema::hasColumn($table, 'id') ? 'id' : null;
    }

    /**
     * @param  array<int, string>  $tables
     */
    private function resetPostgresSequences($target, array $tables): void
    {
        $this->newLine();
        $this->info('Resetting PostgreSQL identity sequences for tables with an integer id...');

        foreach ($tables as $table) {
            if (! Schema::connection($target->getName())->hasColumn($table, 'id')) {
                continue;
            }
            $columnType = Schema::connection($target->getName())->getColumnType($table, 'id');
            if (! in_array($columnType, ['integer', 'bigint'], true)) {
                continue; // string/UUID primary key — no sequence to reset.
            }

            try {
                $target->statement(
                    "SELECT setval(pg_get_serial_sequence(?, 'id'), COALESCE((SELECT MAX(id) FROM \"{$table}\"), 1), (SELECT MAX(id) FROM \"{$table}\") IS NOT NULL)",
                    [$table]
                );
            } catch (\Throwable $e) {
                // Table has no owned sequence (e.g. id isn't actually
                // serial/identity) — nothing to reset, not fatal.
                $this->warn("  {$table}: sequence reset skipped ({$e->getMessage()})");

                continue;
            }
        }
    }
}
