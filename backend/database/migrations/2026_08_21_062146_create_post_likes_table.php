<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Real bug fix (docs/EKSIKLER.md sosyal §1): FeedPost.liked_by_me
        // used to be a single boolean column on the post itself, shared by
        // every account — one real user liking a post made it show as
        // "liked" for every other real account too. This table makes likes
        // genuinely per-user, the same way post_comments is already
        // genuinely per-author.
        Schema::create('post_likes', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('post_id');
            $table->foreign('post_id')->references('id')->on('feed_posts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['post_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_likes');
    }
};
