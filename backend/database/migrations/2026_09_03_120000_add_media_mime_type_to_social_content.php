<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feed_posts', function (Blueprint $table) {
            $table->string('media_mime_type')->nullable()->after('image_url');
        });
        Schema::table('stories', function (Blueprint $table) {
            $table->string('media_mime_type')->nullable()->after('image_url');
        });
    }

    public function down(): void
    {
        Schema::table('feed_posts', function (Blueprint $table) {
            $table->dropColumn('media_mime_type');
        });
        Schema::table('stories', function (Blueprint $table) {
            $table->dropColumn('media_mime_type');
        });
    }
};
