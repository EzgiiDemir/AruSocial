<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Matches PostComment.fromJson: {id, author, text, meta}.
        Schema::create('post_comments', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('post_id');
            $table->foreign('post_id')->references('id')->on('feed_posts')->cascadeOnDelete();
            $table->string('author');
            $table->text('text');
            $table->string('meta')->default('');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('post_comments');
    }
};
