<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Locally-stored text of the public ARUCAD web pages the knowledge crawler
 * reads. This is the self-hosted alternative to an external search/RAG
 * service: Ask ARUVERSE retrieves from this table, so no student question is
 * ever sent to a third-party crawler and the site content lives in our own DB.
 *
 * One row per URL, replaced in place on each crawl. No personal data — only
 * public page text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_documents', function (Blueprint $table) {
            // Deterministic id (sha1 of the normalized URL) so a re-crawl
            // updates the same row rather than inserting a duplicate.
            $table->string('id')->primary();
            $table->string('url', 1024);
            $table->string('domain');
            $table->string('title')->nullable();
            $table->text('content');

            // Content hash lets a crawl skip re-writing (and re-indexing) a
            // page whose text has not changed since last time.
            $table->string('content_hash', 64)->nullable();
            $table->unsignedInteger('content_length')->default(0);

            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('language', 8)->nullable();

            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->index('domain');
            $table->index('fetched_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_documents');
    }
};
