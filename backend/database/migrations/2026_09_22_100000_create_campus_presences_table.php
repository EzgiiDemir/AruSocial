<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Live "who is actually at this building right now" presence.
     *
     * Deliberately NOT a location history: one row per user, overwritten on
     * every ping, holding only the place the server resolved the ping to —
     * never the raw coordinates. Nothing here can reconstruct where someone
     * walked, and a row older than LiveCrowd::WINDOW_MINUTES is ignored and
     * pruned. Check-ins (`checkins`) stay a separate, deliberate social act
     * with its own history; this table is the passive signal behind the
     * map's crowd counts.
     */
    public function up(): void
    {
        Schema::create('campus_presences', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('place_id');
            $table->foreign('place_id')->references('id')->on('places')->cascadeOnDelete();
            $table->timestamp('updated_at');
            // The one query this table exists for: live head count per place.
            $table->index(['place_id', 'updated_at']);
            // Pruning expired rows across every place.
            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campus_presences');
    }
};
