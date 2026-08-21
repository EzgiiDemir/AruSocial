<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Superseded by post_likes — see that migration's doc comment.
        Schema::table('feed_posts', function (Blueprint $table) {
            $table->dropColumn('liked_by_me');
        });
    }

    public function down(): void
    {
        Schema::table('feed_posts', function (Blueprint $table) {
            $table->boolean('liked_by_me')->default(false);
        });
    }
};
