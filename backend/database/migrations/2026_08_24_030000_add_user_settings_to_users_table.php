<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Domain cleanup: location visibility / nearby-discoverable / default
// check-in visibility / personalization lived only in device-local
// SharedPreferences (`AppSettingsStore`). A second device (or a reinstall)
// silently reset every privacy choice. These columns are the shared
// source of truth; the Flutter enum `.name` values (ghost/friends/
// community/public) are stored as-is so the client can round-trip without
// a lookup table. Defaults match the previous client defaults (most
// private for location, check-ins visible, personalization on).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('location_visibility')->default('ghost');
            $table->boolean('nearby_discoverable')->default(false);
            $table->boolean('check_in_visible')->default(true);
            $table->boolean('personalization')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'location_visibility',
                'nearby_discoverable',
                'check_in_visible',
                'personalization',
            ]);
        });
    }
};
