<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notifications') || Schema::hasColumn('notifications', 'actor_user_id')) {
            return;
        }

        Schema::table('notifications', function (Blueprint $table) {
            $table->foreignId('actor_user_id')
                ->nullable()
                ->after('user_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('notifications') || ! Schema::hasColumn('notifications', 'actor_user_id')) {
            return;
        }

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('actor_user_id');
        });
    }
};
