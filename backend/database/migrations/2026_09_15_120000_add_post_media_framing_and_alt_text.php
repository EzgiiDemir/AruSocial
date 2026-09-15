<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carousels, non-destructive framing and alt text for posts.
 *
 * Three needs, one migration, because they all describe the same thing —
 * what a post's pictures are and how they should be shown.
 *
 * `feed_posts.image_url` is deliberately left exactly as it is. Every
 * existing post, every client build already in someone's hand, and the
 * moderation pipeline all read that column, so it stays the single image
 * of a single-image post and keeps being written for the first item of a
 * carousel. `feed_post_media` is additive: a post with no rows there
 * behaves precisely as it does today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feed_posts', function (Blueprint $table) {
            // Same shape and purpose as `stories.style_json`: the author's
            // framing, not a transformation already burned into the file.
            $table->text('style_json')->nullable()->after('media_mime_type');

            // Alt text belongs to the post while a post has one image. A
            // carousel's alt text is per item, on the row below.
            $table->text('alt_text')->nullable()->after('style_json');
        });

        Schema::create('feed_post_media', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('post_id');
            $table->string('media_url');

            // 'image' today. The column exists so that adding video later is
            // a value change rather than a schema change, which is the only
            // part of video worth building before the rest of it exists.
            $table->string('media_type')->default('image');

            $table->unsignedInteger('sort_order')->default(0);

            // Intrinsic pixel size, recorded at upload. The feed needs the
            // aspect ratio before the bytes arrive, or every card resizes
            // as its image loads and the whole list jumps.
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->float('aspect_ratio')->nullable();

            $table->text('style_json')->nullable();
            $table->text('alt_text')->nullable();
            $table->timestamps();

            $table->foreign('post_id')->references('id')->on('feed_posts')->cascadeOnDelete();

            // The feed reads one post's items in author order, always.
            $table->unique(['post_id', 'sort_order']);
            $table->index('post_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_post_media');

        Schema::table('feed_posts', function (Blueprint $table) {
            $table->dropColumn(['style_json', 'alt_text']);
        });
    }
};
