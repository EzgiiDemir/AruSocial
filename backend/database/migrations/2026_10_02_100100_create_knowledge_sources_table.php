<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Individual URLs an operator wants AICAD to read.
 *
 * `crawl_sources` is one row per SITE and decides what may be crawled at all;
 * this is one row per PAGE and decides what must be crawled, even when no
 * sitemap or link leads to it. A URL here is only fetched when its host is
 * also an allowed crawl domain, so this table cannot widen the allow-list.
 * Fetch status is not duplicated here — it lives on knowledge_documents,
 * keyed by the same URL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_sources', function (Blueprint $table) {
            $table->id();
            $table->string('url', 700)->unique();
            $table->string('label', 255)->nullable();
            $table->string('locale', 8)->nullable();
            $table->boolean('enabled')->default(true);
            $table->text('notes')->nullable();
            $table->string('created_by', 191)->nullable();
            // Outcome of the last crawl started from the admin panel, so an
            // operator sees a failure even when no document row was written.
            $table->string('last_crawl_status', 32)->nullable();
            $table->string('last_crawl_error', 500)->nullable();
            $table->timestamp('last_crawl_requested_at')->nullable();
            $table->timestamp('last_crawled_at')->nullable();
            $table->timestamps();

            $table->index('enabled');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_sources');
    }
};
