<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_thread_prefs', function (Blueprint $table) {
            $table->timestamp('last_read_at')->nullable()->after('restricted_at');
        });
    }

    public function down(): void
    {
        Schema::table('chat_thread_prefs', function (Blueprint $table) {
            $table->dropColumn('last_read_at');
        });
    }
};
