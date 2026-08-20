<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Mirrors RoleAssignmentStore's email->role table. Role stays a
        // plain string matching the real Dart UserRole enum (student,
        // clubManager, contentEditor, moderator, careerStaff,
        // studentAffairs, superAdmin) rather than a separate normalized
        // roles table — there's no dynamic role creation anywhere in the
        // Flutter UI, so a fixed enum-as-string is honest, not a shortcut.
        Schema::create('role_assignments', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('role');
            $table->string('assigned_by');
            $table->timestamp('assigned_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_assignments');
    }
};
