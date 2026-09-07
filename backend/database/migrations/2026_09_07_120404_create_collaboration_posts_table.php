<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Real replacement for the previously hardcoded, fake `_collaborationBoard`
// const shown on every workshop-category place in the Flutter map sheet.
// Posts are student-created (material swaps, "looking for a model" asks)
// and auto-expire so the board never goes stale.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collaboration_posts', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('place_id');
            $table->foreign('place_id')->references('id')->on('places')->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->text('text');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collaboration_posts');
    }
};
