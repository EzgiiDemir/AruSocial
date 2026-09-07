<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->string('subject', 200)->nullable()->after('application_id');
            $table->text('notes')->nullable()->after('subject');
            $table->text('admin_notes')->nullable()->after('notes');
        });

        Schema::table('career_profiles', function (Blueprint $table) {
            $table->string('expertise', 200)->nullable()->after('headline');
            $table->string('cv_path')->nullable()->after('cv_url');
            $table->string('cv_original_name')->nullable()->after('cv_path');
            $table->string('cv_mime', 80)->nullable()->after('cv_original_name');
            $table->unsignedInteger('cv_size_bytes')->nullable()->after('cv_mime');
        });

        Schema::table('career_opportunities', function (Blueprint $table) {
            $table->string('department', 200)->nullable()->after('organization');
            $table->text('purpose')->nullable()->after('description');
            $table->text('skills')->nullable()->after('purpose');
            $table->text('experience')->nullable()->after('skills');
            $table->text('education')->nullable()->after('experience');
            $table->string('work_type', 80)->nullable()->after('education');
            $table->string('location', 200)->nullable()->after('work_type');
            $table->date('posted_at')->nullable()->after('deadline');
            $table->text('extra_info')->nullable()->after('posted_at');
        });

        Schema::create('career_applications', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('opportunity_id');
            $table->string('cv_path')->nullable();
            $table->string('cv_original_name')->nullable();
            $table->string('cv_mime', 80)->nullable();
            $table->string('status')->default('pending');
            $table->text('admin_notes')->nullable();
            $table->timestamps();
            $table->foreign('opportunity_id')->references('id')->on('career_opportunities')->cascadeOnDelete();
            $table->index(['opportunity_id', 'status']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('consultations', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('title');
            $table->text('purpose')->nullable();
            $table->text('audience')->nullable();
            $table->text('content')->nullable();
            $table->text('outcomes')->nullable();
            $table->string('duration')->nullable();
            $table->string('format')->nullable();
            $table->text('requirements')->nullable();
            $table->string('counselor_name')->nullable();
            $table->string('counselor_staff_id')->nullable();
            $table->boolean('published')->default(true);
            $table->timestamps();
            $table->index(['published', 'created_at']);
        });

        Schema::create('consultation_applications', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('consultation_id');
            $table->string('status')->default('pending');
            $table->text('notes')->nullable();
            $table->text('admin_notes')->nullable();
            $table->timestamps();
            $table->foreign('consultation_id')->references('id')->on('consultations')->cascadeOnDelete();
            $table->index(['consultation_id', 'status']);
            $table->index(['user_id', 'status']);
        });

        Schema::table('feed_posts', function (Blueprint $table) {
            $table->boolean('is_pinned')->default(false);
            $table->timestamp('pinned_at')->nullable();
            $table->unsignedBigInteger('pinned_by')->nullable();
            $table->index(['is_pinned', 'pinned_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_private_profile')->default(false);
        });

        DB::statement('DROP INDEX IF EXISTS appointments_booked_slot_unique');
        DB::statement(
            "CREATE UNIQUE INDEX IF NOT EXISTS appointments_active_slot_unique
             ON appointments (staff_profile_id, slot_date, start_time)
             WHERE status IN ('pending', 'approved', 'booked')"
        );
        DB::statement(
            "CREATE UNIQUE INDEX IF NOT EXISTS career_applications_open_unique
             ON career_applications (user_id, opportunity_id)
             WHERE status IN ('pending', 'reviewed', 'shortlisted')"
        );
        DB::statement(
            "CREATE UNIQUE INDEX IF NOT EXISTS consultation_applications_open_unique
             ON consultation_applications (user_id, consultation_id)
             WHERE status IN ('pending', 'reviewed', 'shortlisted')"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS appointments_active_slot_unique');
        DB::statement('DROP INDEX IF EXISTS career_applications_open_unique');
        DB::statement('DROP INDEX IF EXISTS consultation_applications_open_unique');
        DB::statement(
            "CREATE UNIQUE INDEX IF NOT EXISTS appointments_booked_slot_unique
             ON appointments (staff_profile_id, slot_date, start_time)
             WHERE status = 'booked'"
        );

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_private_profile');
        });
        Schema::table('feed_posts', function (Blueprint $table) {
            $table->dropColumn(['is_pinned', 'pinned_at', 'pinned_by']);
        });
        Schema::dropIfExists('consultation_applications');
        Schema::dropIfExists('consultations');
        Schema::dropIfExists('career_applications');
        Schema::table('career_opportunities', function (Blueprint $table) {
            $table->dropColumn([
                'department', 'purpose', 'skills', 'experience', 'education',
                'work_type', 'location', 'posted_at', 'extra_info',
            ]);
        });
        Schema::table('career_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'expertise', 'cv_path', 'cv_original_name', 'cv_mime', 'cv_size_bytes',
            ]);
        });
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn(['subject', 'notes', 'admin_notes']);
        });
    }
};
