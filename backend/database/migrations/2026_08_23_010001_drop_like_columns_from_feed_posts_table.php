<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Both columns are superseded by post_likes (see that migration).
        //
        // `likes` goes too, not just `liked_by_me`. Keeping a counter next
        // to the rows it counts means having two answers to one question
        // and a way for them to disagree — and the counter is the one that
        // can't be recomputed once it drifts. The API still returns a
        // `likes` number; it's now derived from the rows.
        Schema::table('feed_posts', function (Blueprint $table) {
            foreach (['liked_by_me', 'likes'] as $column) {
                if (Schema::hasColumn('feed_posts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('feed_posts', function (Blueprint $table) {
            if (! Schema::hasColumn('feed_posts', 'liked_by_me')) {
                $table->boolean('liked_by_me')->default(false);
            }
            if (! Schema::hasColumn('feed_posts', 'likes')) {
                $table->integer('likes')->default(0);
            }
        });
    }
};
