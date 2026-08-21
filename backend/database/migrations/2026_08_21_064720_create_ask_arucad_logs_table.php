<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Real question logging for Ask ARUCAD analytics (docs/EKSIKLER.md
        // admin §5) — previously AiController::query() was a stateless
        // proxy to Groq that logged nothing at all, so "en çok sorulan
        // sorular / kategori bazında istatistik" was structurally
        // impossible to answer. Every real question now gets a real row,
        // regardless of whether the Groq call itself succeeds — the point
        // is what students asked, not just what got answered.
        Schema::create('ask_arucad_logs', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('question');
            $table->string('category');
            $table->timestamp('created_at');
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ask_arucad_logs');
    }
};
