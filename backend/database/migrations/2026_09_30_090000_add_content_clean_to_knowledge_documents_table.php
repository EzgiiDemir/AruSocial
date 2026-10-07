<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The page text with the site menu already removed.
 *
 * `content` stays exactly as crawled, because BoilerplateFilter decides what
 * counts as chrome by looking at what repeats across the corpus — it needs the
 * raw pages to do that, and a column it had already cleaned would tell it
 * nothing.
 *
 * Retrieval wants the other form. It scores every indexed document on every
 * question, and stripping 1,181 pages while ranking them was measured at
 * 425 ms per question, against 31 ms to fold the same text for matching. The
 * strip only changes when a page is re-crawled, so paying for it per question
 * was paying for the same answer hundreds of times a day.
 *
 * Nullable on purpose: until a page has been through the indexer this is null
 * and retrieval strips on the fly, so the corpus keeps working during a
 * deploy rather than briefly answering from un-cleaned text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_documents', function (Blueprint $table) {
            $table->longText('content_clean')->nullable()->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_documents', function (Blueprint $table) {
            $table->dropColumn('content_clean');
        });
    }
};
