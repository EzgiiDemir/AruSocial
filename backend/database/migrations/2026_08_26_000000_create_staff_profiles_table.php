<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Hardening-2: academic staff directory (not login accounts). email may be
// null when unverified — never invent production addresses.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_profiles', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->string('faculty')->nullable();
            $table->string('department')->nullable();
            $table->string('title')->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_department_head')->default(false);
            $table->boolean('active')->default(true);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['department', 'active']);
            $table->index(['faculty', 'active']);
        });

        Schema::table('clubs', function (Blueprint $table) {
            $table->string('responsible_staff_id')->nullable();
        });
        Schema::table('sports', function (Blueprint $table) {
            $table->string('responsible_staff_id')->nullable();
        });
        Schema::table('services', function (Blueprint $table) {
            $table->string('responsible_staff_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('responsible_staff_id');
        });
        Schema::table('sports', function (Blueprint $table) {
            $table->dropColumn('responsible_staff_id');
        });
        Schema::table('clubs', function (Blueprint $table) {
            $table->dropColumn('responsible_staff_id');
        });
        Schema::dropIfExists('staff_profiles');
    }
};
