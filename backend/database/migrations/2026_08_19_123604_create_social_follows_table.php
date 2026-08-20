<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mirrors SocialGraphStore.following() — target is a peer *name*
        // (matching the leaderboard's real roster), not a user_id, because
        // this prototype's other real students are seed rows, not real
        // accounts with their own login yet (see docs/EKSIKLER.md §4).
        Schema::create('social_follows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('follower_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('followed_name');
            $table->timestamp('created_at');
            $table->unique(['follower_user_id', 'followed_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_follows');
    }
};
