<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A like is a fact about a person and a post together, so it gets a
        // row of its own. It used to be `feed_posts.liked_by_me`, a single
        // boolean on the post — which only ever made sense while there was
        // one account. With real per-user sign-in it meant one person
        // liking a post showed it as liked for everyone.
        //
        // The unique index is the actual guarantee: "you can like a post
        // once" is enforced by the database, not by a controller
        // remembering to check first.
        if (Schema::hasTable('post_likes')) {
            return;
        }

        Schema::create('post_likes', function (Blueprint $table) {
            $table->string('id')->primary();

            // Both sides cascade because a like has no meaning without
            // them: a deleted post's likes describe nothing, and a deleted
            // account's likes belong to nobody. Neither is data worth
            // orphaning to preserve a count.
            $table->string('post_id');
            $table->foreign('post_id')->references('id')->on('feed_posts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'post_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_likes');
    }
};
