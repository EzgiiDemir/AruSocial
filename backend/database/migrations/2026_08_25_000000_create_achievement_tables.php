<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// P4 Mega-1: real achievement definitions + per-user unlock rows.
// Distinct from users.achievements (DC-2 free-text bio field).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('achievement_definitions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('title');
            $table->string('subtitle')->default('');
            $table->string('trigger_kind');
            $table->unsignedInteger('threshold')->default(1);
            $table->unsignedInteger('sort_order')->default(0);
        });

        Schema::create('user_achievements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('achievement_id');
            $table->timestamp('unlocked_at');
            $table->unique(['user_id', 'achievement_id']);
            $table->foreign('achievement_id')
                ->references('id')
                ->on('achievement_definitions')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_achievements');
        Schema::dropIfExists('achievement_definitions');
    }
};
