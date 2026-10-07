<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two more canonical fields AICAD answers from (Phase 4B):
 *
 *  - services.phone: a phone number of its own, validated on entry. Until
 *    now a number could only be typed into the free-text `contact` field
 *    next to the e-mail, and none was. `contact` keeps working.
 *  - sports.place_id: the campus place a team trains or plays at. The
 *    `facility` text ("Spor Salonu", "Açık Saha") matches no place record,
 *    and a name is never matched automatically.
 *
 * Additive and nullable; nothing is back-filled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->string('phone', 40)->nullable();
        });

        Schema::table('sports', function (Blueprint $table) {
            $table->string('place_id')->nullable();
            $table->foreign('place_id')->references('id')->on('places')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sports', function (Blueprint $table) {
            $table->dropForeign(['place_id']);
            $table->dropColumn('place_id');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('phone');
        });
    }
};
