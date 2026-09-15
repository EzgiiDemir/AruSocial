<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['feed_posts', 'post_comments', 'stories', 'messages', 'chat_group_messages', 'reviews', 'collaboration_posts'] as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'moderation_status')) {
                Schema::table($table, function (Blueprint $blueprint): void {
                    // Existing published rows remain published. New write
                    // paths run moderation before insert and explicitly set
                    // approved; BLOCK/ERROR rows are never inserted.
                    $blueprint->string('moderation_status', 20)->default('approved')->index();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['feed_posts', 'post_comments', 'stories', 'messages', 'chat_group_messages', 'reviews', 'collaboration_posts'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'moderation_status')) {
                Schema::table($table, function (Blueprint $blueprint): void {
                    $blueprint->dropIndex(['moderation_status']);
                    $blueprint->dropColumn('moderation_status');
                });
            }
        }
    }
};
