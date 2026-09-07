<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Hardening-2: staff availability slots + student appointments.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_availability_slots', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('staff_profile_id');
            $table->date('slot_date');
            $table->string('start_time', 8); // HH:MM:SS or HH:MM
            $table->string('end_time', 8);
            $table->boolean('is_blocked')->default(false);
            $table->timestamps();
            $table->foreign('staff_profile_id')->references('id')->on('staff_profiles')->cascadeOnDelete();
            $table->unique(['staff_profile_id', 'slot_date', 'start_time'], 'staff_slot_unique');
        });

        Schema::create('appointments', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('staff_profile_id');
            $table->foreignId('student_user_id')->constrained('users')->cascadeOnDelete();
            $table->date('slot_date');
            $table->string('start_time', 8);
            $table->string('end_time', 8);
            $table->string('application_id')->nullable();
            $table->string('status')->default('booked'); // booked|cancelled|completed
            $table->timestamps();
            $table->foreign('staff_profile_id')->references('id')->on('staff_profiles')->cascadeOnDelete();
            $table->index(['staff_profile_id', 'slot_date', 'status']);
            $table->index(['student_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('staff_availability_slots');
    }
};
