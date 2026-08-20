<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Real in-app notification inbox (kind/title/body/read state) —
        // separate from push delivery (push_tokens table): this is what a
        // real other user's action would insert here (e.g. a follow), so
        // there's a genuine unread-count and inbox even before FCM/APNs
        // credentials exist to also push it to a device.
        Schema::create('notifications', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind'); // follow | like | comment | event | admin | system
            $table->string('title');
            $table->string('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
