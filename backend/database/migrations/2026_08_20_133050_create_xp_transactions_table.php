<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Real XP ledger (docs/EKSIKLER.md harita/check-in/XP) — before
        // this, `users.xp` was just an incrementing counter with no record
        // of *why* it changed. Every XP grant now gets a real, permanent
        // row here: how much, why, and which real source record (a
        // check-in or event join) caused it — `users.xp` itself stays as
        // the fast-read running total, this is the auditable history
        // behind it.
        Schema::create('xp_transactions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('amount');
            $table->string('reason');
            $table->string('source_type');
            $table->string('source_id')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('xp_transactions');
    }
};
