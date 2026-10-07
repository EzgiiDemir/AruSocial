<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Curated keywords for individual ARUCAD pages.
 *
 * `crawl_sources.keys` says what a whole SITE is about, which is the right
 * granularity for routing a question to a domain and the wrong one for
 * choosing between nine hundred pages on that domain. These rows say what
 * each PAGE is about.
 *
 * A separate table rather than a column on `knowledge_documents` because the
 * two have different lifetimes: keywords are authored once for a URL and must
 * survive the page being re-crawled, dropped from the index, or not yet
 * reached at all. Of the 852 URLs in the first import, 381 were not yet
 * crawled — filing their keywords on the document row would have discarded
 * them and needed a second import later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_keywords', function (Blueprint $table) {
            // The URL is the identity: these are authored against the public
            // address, not against our internal document id.
            $table->string('url', 700)->primary();
            $table->string('title', 500)->nullable();
            $table->string('language', 8)->nullable();
            $table->string('category', 190)->nullable();
            // Comma-separated, like crawl_sources.keys, for the same reason:
            // a handful of short strings per row, edited as text.
            $table->text('keywords')->nullable();
            $table->text('summary')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_keywords');
    }
};
