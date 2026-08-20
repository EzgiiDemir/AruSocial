<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The real structured date behind place-availability/date-conflict
        // checking (docs/EKSIKLER.md §4) — `time` stays the free-text
        // display string ("14:00"), this is what conflict detection
        // actually compares.
        Schema::table('events', function (Blueprint $table) {
            $table->date('event_date')->nullable()->after('time');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn('event_date');
        });
    }
};
