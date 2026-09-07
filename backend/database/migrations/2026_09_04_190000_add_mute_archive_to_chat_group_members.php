<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_group_members', function (Blueprint $table) {
            $table->timestamp('muted_at')->nullable()->after('user_id');
            $table->timestamp('archived_at')->nullable()->after('muted_at');
        });
    }

    public function down(): void
    {
        Schema::table('chat_group_members', function (Blueprint $table) {
            $table->dropColumn(['muted_at', 'archived_at']);
        });
    }
};
