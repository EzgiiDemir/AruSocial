<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 360 directory navigation.tourTarget (3DVista media-name). Optional —
// many tours already encode the target in the URL fragment instead.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('places', function (Blueprint $table) {
            $table->string('tour_target', 500)->nullable()->after('tour_url');
        });
    }

    public function down(): void
    {
        Schema::table('places', function (Blueprint $table) {
            $table->dropColumn('tour_target');
        });
    }
};
