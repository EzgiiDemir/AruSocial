<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('directory_entries', function (Blueprint $table) {
            $table->string('tour_url', 1000)->nullable()->after('related_service_id');
            $table->string('tour_target', 500)->nullable()->after('tour_url');
        });
    }

    public function down(): void
    {
        Schema::table('directory_entries', function (Blueprint $table) {
            $table->dropColumn(['tour_url', 'tour_target']);
        });
    }
};
