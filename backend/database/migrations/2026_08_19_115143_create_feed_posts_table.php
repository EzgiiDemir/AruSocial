<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Matches FeedPost.fromJson in lib/core/models/campus_models.dart.
        Schema::create('feed_posts', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('author_id')->default('');
            $table->string('name');
            $table->text('text')->default('');
            $table->string('meta')->default('');
            $table->integer('likes')->default(0);
            $table->boolean('liked_by_me')->default(false);
            $table->string('image_url')->nullable();
            $table->string('visibility')->default('everyone');
            $table->string('post_type')->default('normal');
            $table->string('course_tag')->nullable();
            $table->string('location_tag')->nullable();
            $table->boolean('official')->default(false);
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feed_posts');
    }
};
