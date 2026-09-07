<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_thread_prefs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('peer_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('muted_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamp('restricted_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'peer_user_id']);
        });

        if (Schema::hasTable('notifications') && ! Schema::hasColumn('notifications', 'data')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->json('data')->nullable()->after('body');
            });
        }

        Schema::create('story_views', function (Blueprint $table) {
            $table->id();
            $table->string('story_id');
            $table->foreignId('viewer_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('viewed_at');
            $table->foreign('story_id')->references('id')->on('stories')->cascadeOnDelete();
            $table->unique(['story_id', 'viewer_user_id']);
        });

        Schema::create('chat_groups', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('chat_group_members', function (Blueprint $table) {
            $table->id();
            $table->string('group_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->foreign('group_id')->references('id')->on('chat_groups')->cascadeOnDelete();
            $table->unique(['group_id', 'user_id']);
        });

        Schema::create('chat_group_messages', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('group_id');
            $table->foreignId('sender_user_id')->constrained('users')->cascadeOnDelete();
            $table->text('text');
            $table->timestamp('created_at');
            $table->foreign('group_id')->references('id')->on('chat_groups')->cascadeOnDelete();
            $table->index(['group_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_group_messages');
        Schema::dropIfExists('chat_group_members');
        Schema::dropIfExists('chat_groups');
        Schema::dropIfExists('story_views');

        if (Schema::hasTable('notifications') && Schema::hasColumn('notifications', 'data')) {
            Schema::table('notifications', function (Blueprint $table) {
                $table->dropColumn('data');
            });
        }

        Schema::dropIfExists('chat_thread_prefs');
    }
};
