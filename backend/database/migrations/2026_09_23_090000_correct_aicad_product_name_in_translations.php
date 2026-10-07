<?php

use App\Services\Translations\TranslationCatalogue;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The product is AICAD, not "Aicad".
 *
 * `app_strings.dart` was corrected some time ago, but the app reads its
 * chrome from the admin-managed `translations` table, which wins over the
 * compiled strings — that is the whole point of the table. Those rows were
 * imported back when the Dart said "Aicad", so the bottom navigation tab
 * kept showing the old spelling on every device no matter what the app
 * bundle said. Nine rows: nav_ask, nav_ask_short and nav_ask_needs_session,
 * in all three locales.
 *
 * A data migration rather than a one-off query, because the stale rows are
 * in every environment that ever ran the import, not just one laptop.
 *
 * Case-sensitive on purpose: `replace()` is case-sensitive on both
 * PostgreSQL and SQLite, and "AICAD" does not contain "Aicad", so running
 * this twice is a no-op rather than producing "AAICADicad".
 */
return new class extends Migration
{
    private const COLUMNS = ['draft', 'published'];

    public function up(): void
    {
        if (! Schema::hasTable('translations')) {
            return;
        }

        foreach (self::COLUMNS as $column) {
            if (! Schema::hasColumn('translations', $column)) {
                continue;
            }

            DB::table('translations')
                ->where($column, 'like', '%Aicad%')
                ->update([$column => DB::raw("replace({$column}, 'Aicad', 'AICAD')")]);
        }

        // The phone caches the catalogue against an ETag and would keep the
        // old spelling until something else happened to publish. Bumping
        // the version is what actually makes the tab change.
        $this->bumpCatalogueVersion();
    }

    /**
     * Deliberately NOT reversible.
     *
     * Rolling back would mean deliberately restoring a misspelling of the
     * product name, and a down() that quietly reintroduces it is worse
     * than one that does nothing.
     */
    public function down(): void {}

    private function bumpCatalogueVersion(): void
    {
        try {
            TranslationCatalogue::forget();
        } catch (\Throwable) {
            // A migration must not fail because the cache store is not
            // reachable (a fresh container, a CI box with no cache table).
            // The text is corrected either way; the worst case is that a
            // device serves the old catalogue until its next publish.
        }
    }
};
