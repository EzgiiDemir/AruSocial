<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Domain cleanup: club membership was device-local only (AppSettingsStore
// SharedPreferences `settings.clubs.joined`) — no roster existed anywhere
// shared, so two students' devices could each believe they were "the only
// member" and nobody else could ever see who actually joined. This table
// is the real, shared join. Mirrors saved_posts (same user_id + string FK
// shape, same unique pair) rather than inventing a new pivot pattern.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('club_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('club_id');
            $table->foreign('club_id')->references('id')->on('clubs')->cascadeOnDelete();
            $table->timestamp('created_at');
            $table->unique(['user_id', 'club_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('club_members');
    }
};
