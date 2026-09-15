<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_pages', function (Blueprint $table) {
            $table->json('translations')->nullable()->after('slug');
            $table->json('audiences')->nullable()->after('status');
            $table->timestamp('publish_at')->nullable()->after('audiences');
            $table->timestamp('expires_at')->nullable()->after('publish_at');
        });
    }

    public function down(): void
    {
        Schema::table('admin_pages', function (Blueprint $table) {
            $table->dropColumn(['translations', 'audiences', 'publish_at', 'expires_at']);
        });
    }
};
