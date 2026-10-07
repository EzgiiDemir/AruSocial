<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The page text folded for matching: lowercase, diacritics removed.
 *
 * Retrieval compares folded strings so that a question typed without Turkish
 * diacritics still finds the page. Folding is cheap per page and ruinous per
 * question: it happens for every indexed document on every question, and at
 * 2,202 pages that measured about 1.2 seconds of the 1.4 seconds a question
 * took.
 *
 * It was previously held in a process-lifetime cache instead, which made the
 * second question fast and cost a copy of the whole corpus in memory — that
 * is what pushed a single request to 130 MB against a 128 MB limit. Storing
 * it fixes both: nothing to fold at question time and nothing to keep
 * resident.
 *
 * Nullable, like content_clean: a page crawled since the last index folds on
 * the fly rather than being unsearchable until the indexer catches up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_documents', function (Blueprint $table) {
            $table->longText('content_folded')->nullable()->after('content_clean');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_documents', function (Blueprint $table) {
            $table->dropColumn('content_folded');
        });
    }
};
