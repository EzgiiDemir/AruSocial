<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Panel-managed translations, so changing a word does not need an App Store
 * release.
 *
 * Today the app's 594 strings are compiled into `app_strings.dart`. Fixing a
 * typo means a build, a review and a store submission — days, for one word.
 *
 * Three tables rather than one, because the requirement asks for preview and
 * publish, history and rollback, and those are three different lifetimes:
 *
 *  - `translation_keys`      what a string IS: its name, what it means, and
 *                            the placeholders it must contain. Language-free.
 *  - `translations`          one row per key per locale, holding the draft
 *                            being worked on AND the published value the app
 *                            is serving. Two columns, because "what the
 *                            translator is writing" and "what a student sees"
 *                            must be able to differ.
 *  - `translation_revisions` every published value that ever was, so rollback
 *                            is selecting a row rather than remembering.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('translation_keys', function (Blueprint $table) {
            $table->id();

            // Matches the key already used in app_strings.dart, e.g.
            // `nav_profile`, so the import is a straight mapping and the
            // Flutter side keeps calling `t('nav_profile')`.
            $table->string('key', 120)->unique();

            // What this string is for, in the translator's words. A key
            // alone ("sp_private") does not tell somebody translating into
            // Russian where it appears or how long it may be.
            $table->string('description', 300)->nullable();

            // Where it appears, for filtering a 594-row table down to the
            // screen somebody is actually working on.
            $table->string('area', 60)->nullable()->index();

            /*
             * The placeholders this string MUST contain, e.g. ["name"] for
             * "Welcome, {name}". Stored per key rather than per locale
             * because a translation that drops a placeholder is broken in
             * every language — the app would render "Welcome, " with a
             * missing name. Validation reads this.
             */
            $table->json('placeholders')->nullable();

            /*
             * Plural strings hold a JSON object keyed by CLDR category
             * ("one", "other", …) instead of a flat string. Turkish and
             * English take one/other; Russian takes one/few/many/other, and
             * getting Russian plurals wrong is the classic way a translated
             * app reads as broken to native speakers.
             */
            $table->boolean('is_plural')->default(false);

            $table->timestamps();
        });

        Schema::create('translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('translation_key_id')
                ->constrained('translation_keys')
                ->cascadeOnDelete();

            $table->string('locale', 5);

            // The working copy. Editing this changes nothing a student sees.
            $table->text('draft')->nullable();

            // What the app serves. Only `publish` writes here.
            $table->text('published')->nullable();

            $table->timestamp('published_at')->nullable();
            $table->string('updated_by', 120)->nullable();
            $table->timestamps();

            // One row per key per language, enforced by the database rather
            // than by remembering: two rows for the same pair would mean the
            // app's answer depends on row order.
            $table->unique(['translation_key_id', 'locale']);

            // The app's own query: every published string for one language.
            $table->index(['locale', 'published_at']);
        });

        Schema::create('translation_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('translation_key_id')
                ->constrained('translation_keys')
                ->cascadeOnDelete();

            $table->string('locale', 5);
            $table->text('value')->nullable();

            // Who published it and when — the audit trail the brief asks for
            // on critical actions, and what makes "restore the version from
            // before Tuesday" answerable.
            $table->string('actor', 120)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['translation_key_id', 'locale', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('translation_revisions');
        Schema::dropIfExists('translations');
        Schema::dropIfExists('translation_keys');
    }
};
