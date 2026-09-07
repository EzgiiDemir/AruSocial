<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('social_follows') || Schema::hasColumn('social_follows', 'status')) {
            return;
        }

        Schema::table('social_follows', function (Blueprint $table) {
            $table->string('status')->default('accepted')->after('followed_user_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('social_follows') || ! Schema::hasColumn('social_follows', 'status')) {
            return;
        }

        Schema::table('social_follows', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
