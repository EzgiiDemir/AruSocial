<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Passages of crawled pages, with their embeddings, for semantic search.
 *
 * Chunks rather than whole pages, for a measured reason: the sentence
 * model truncates at 256 tokens, so embedding a page would represent it
 * by its first few paragraphs and nothing else — a fee table halfway
 * down the page would be invisible to search. Each chunk is embedded on
 * its own, and a page scores as its best chunk.
 *
 * It also improves what the model is given to answer from: the passage
 * that actually matched, instead of a window around the first keyword.
 *
 * Derived data, and disposable. Every row can be rebuilt from
 * `knowledge_documents` with `php artisan knowledge:embed --all`, so the
 * table is dropped and recreated rather than migrated if its shape ever
 * changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_chunks', function (Blueprint $table): void {
            $table->id();
            // sha1 of the URL, matching knowledge_documents.id.
            $table->string('knowledge_document_id', 64);
            $table->unsignedSmallInteger('position');
            $table->text('text');
            // Base64 of little-endian float32, written by App\Support\Vector.
            $table->text('embedding')->nullable();
            // Which model produced it: a vector from one model cannot be
            // compared with one from another, and storing this is what
            // makes a model change detectable instead of silently wrong.
            $table->string('model', 120)->nullable();
            $table->string('model_version', 80)->nullable();
            $table->timestamps();

            $table->index(['knowledge_document_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_chunks');
    }
};
