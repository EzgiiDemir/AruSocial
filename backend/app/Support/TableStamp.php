<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * A cheap fingerprint of a table's contents: row count plus newest
 * updated_at. Used as part of a cache key, so a write through ANY path —
 * an Eloquent model, an Eloquent bulk update (which touches updated_at), or
 * a query-builder bulk delete (which changes the count) — invalidates the
 * cached value without depending on model events firing.
 *
 * A raw query-builder UPDATE that does not set updated_at is the one write
 * this cannot see; retrieval data is written through Eloquent.
 */
final class TableStamp
{
    public static function of(string $table): string
    {
        return app(RequestMemo::class)->remember('table-stamp:'.$table, function () use ($table): string {
            $row = DB::table($table)->selectRaw('count(*) as n, max(updated_at) as t')->first();

            return ($row->n ?? 0).'|'.($row->t ?? '');
        });
    }

    /** Drop the memoised stamp after a write within the same request. */
    public static function touched(string $table): void
    {
        app(RequestMemo::class)->forget('table-stamp:'.$table);
    }
}
