<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Separate from `activity_log` (a student's own real activity
        // history) — this is the admin-side audit trail (AuditLogStore):
        // every admin create/update/delete + login/logout + role change.
        Schema::create('admin_audit_log', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('actor_name');
            $table->string('action');
            $table->string('target_type');
            $table->string('target_label');
            $table->timestamp('at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit_log');
    }
};
