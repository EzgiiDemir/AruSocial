<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Re-points every PostgreSQL identity sequence at its table's real maximum.
 *
 * Copying rows from another database inserts primary keys explicitly, which
 * does not advance the sequence behind them. The sequence therefore still
 * reads 1 while the table already holds id 2, and the very next insert
 * collides — `duplicate key value violates unique constraint "users_pkey"`.
 * Every write path breaks at once, immediately after a cutover that looked
 * successful, which is a miserable way to find out.
 *
 * Written against pg_catalog rather than a table list, so it covers tables
 * added later, and it is safe to run repeatedly: setting a sequence to a
 * value it already has changes nothing.
 */
class ResyncPostgresSequences extends Command
{
    protected $signature = 'db:resync-sequences
        {--connection=pgsql : Laravel connection name (must be pgsql)}
        {--dry-run : Report sequences that are behind without changing them}';

    protected $description = 'Set every PostgreSQL sequence to its column maximum after a data import';

    public function handle(): int
    {
        $name = (string) $this->option('connection');

        if (config("database.connections.{$name}.driver") !== 'pgsql') {
            $this->error("--connection={$name} is not a pgsql connection.");

            return self::FAILURE;
        }

        $db = DB::connection($name);

        // Every sequence that is actually owned by a column. Sequences with
        // no owner are not identity columns and must be left alone.
        $owned = $db->select(<<<'SQL'
            SELECT
                s.relname       AS sequence_name,
                t.relname       AS table_name,
                a.attname       AS column_name,
                n.nspname       AS schema_name
            FROM pg_class s
            JOIN pg_depend d      ON d.objid = s.oid AND d.deptype IN ('a', 'i')
            JOIN pg_class t       ON t.oid = d.refobjid
            JOIN pg_attribute a   ON a.attrelid = t.oid AND a.attnum = d.refobjsubid
            JOIN pg_namespace n   ON n.oid = s.relnamespace
            WHERE s.relkind = 'S'
        SQL);

        $behind = 0;
        $checked = 0;

        foreach ($owned as $seq) {
            $checked++;
            $qualifiedTable = '"'.$seq->schema_name.'"."'.$seq->table_name.'"';
            $qualifiedSeq = '"'.$seq->schema_name.'"."'.$seq->sequence_name.'"';
            $column = '"'.$seq->column_name.'"';

            $max = $db->scalar("SELECT COALESCE(MAX({$column}), 0) FROM {$qualifiedTable}");
            $current = $db->scalar("SELECT last_value FROM {$qualifiedSeq}");

            if ((int) $max <= (int) $current) {
                continue;
            }

            $behind++;
            $this->line("  {$seq->table_name}.{$seq->column_name}: sequence at {$current}, data at {$max}");

            if ($this->option('dry-run')) {
                continue;
            }

            // is_called = true, so the next value is max + 1 rather than max
            // itself — which would collide with the row that is already there.
            $db->statement("SELECT setval('{$seq->schema_name}.{$seq->sequence_name}', {$max}, true)");
        }

        if ($behind === 0) {
            $this->info("All {$checked} sequences are already ahead of their data.");

            return self::SUCCESS;
        }

        $this->info($this->option('dry-run')
            ? "{$behind} of {$checked} sequences are behind and would be corrected."
            : "Corrected {$behind} of {$checked} sequences.");

        return self::SUCCESS;
    }
}
