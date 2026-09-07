<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Full audit trail for one application's lifecycle — who changed it, when,
// from what status to what, and with what note. Separate from the generic
// `AuditLogger` (which logs a flat human-readable line for the admin
// Activity Log) because this one is structured and shown back to the
// student on their own application detail screen.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('application_status_events', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('application_id');
            $table->foreign('application_id')->references('id')->on('participation_applications')->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->text('note')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_label')->nullable();
            $table->timestamp('created_at');
            $table->index(['application_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('application_status_events');
    }
};
