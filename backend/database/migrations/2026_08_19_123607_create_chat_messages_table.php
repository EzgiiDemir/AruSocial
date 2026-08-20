<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Real, persisted, per-peer threads — genuinely shared across any
        // client hitting this backend (unlike the on-device ChatStore).
        // Still not real-time (no WebSocket here yet — see
        // docs/EKSIKLER.md §4), but a message sent from one client is now
        // really readable from another poll/refresh.
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('peer_name');
            $table->boolean('from_me');
            $table->text('text');
            $table->timestamp('sent_at');
            $table->index(['user_id', 'peer_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
