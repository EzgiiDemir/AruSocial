<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Domain cleanup: `quests.progress` was a static seeded integer that
// never moved when the student actually checked in or joined an event.
// `kind` tells GET /me/quests which real event to count (`distinct_checkins`
// / `event_joins`); `static` keeps the stored `progress` column for any
// leftover row that has no derived rule. The column itself is left in
// place so a down() doesn't have to invent numbers.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quests', function (Blueprint $table) {
            $table->string('kind')->default('static');
        });
    }

    public function down(): void
    {
        Schema::table('quests', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
