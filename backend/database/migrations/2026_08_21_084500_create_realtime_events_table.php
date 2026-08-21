<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Durable event log for the real, poll-based realtime bus
        // (docs/EKSIKLER.md "Gerçek realtime / event-based mimari").
        // Autoincrement id is the cursor clients use — UUID ordering is
        // not reliable across inserts. audience is 'all', 'admin', or a
        // users.id string for a private recipient.
        Schema::create('realtime_events', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('audience');
            $table->string('entity_type')->nullable();
            $table->string('entity_id')->nullable();
            $table->string('actor_id')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at');
            $table->index(['audience', 'id']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('realtime_events');
    }
};
