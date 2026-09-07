<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

// Real fix: `Schema::hasColumn()` was called on every single request to
// several hot endpoints (feed load, check-in, Ask ARUCAD) purely to support
// running against a database that hasn't run a specific migration yet
// (see LegacySchemaCompatTest) — a real, deliberate compatibility feature,
// not dead code, so it can't just be removed. But the schema itself only
// ever changes via a migration, which happens at deploy time, not per
// request — so the answer is safe to cache for the life of the PHP worker
// process instead of re-querying the database's schema metadata every time.
class SchemaColumnCache
{
    private static array $cache = [];

    public static function hasColumn(string $table, string $column): bool
    {
        $key = "{$table}.{$column}";

        return self::$cache[$key] ??= Schema::hasColumn($table, $column);
    }

    /** Tests that drop/add columns at runtime must clear the stale answer. */
    public static function forget(string $table, string $column): void
    {
        unset(self::$cache["{$table}.{$column}"]);
    }
}
