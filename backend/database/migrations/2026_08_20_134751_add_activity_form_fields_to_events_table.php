<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Real "Kendi Aktiviteni Oluştur" form fields (docs/EKSIKLER.md
        // aktivite/onay workflow §1) — everything beyond what the
        // original quick-create already had (title/place/time/category/
        // description/eventDate). Filled in via the real signed-URL web
        // form (see ActivityFormController), not invented UI padding.
        Schema::table('events', function (Blueprint $table) {
            $table->string('student_number')->nullable()->after('created_by_user_id');
            $table->string('phone')->nullable()->after('student_number');
            $table->string('faculty')->nullable()->after('phone');
            $table->string('department')->nullable()->after('faculty');
            $table->string('end_time')->nullable()->after('time');
            $table->unsignedInteger('estimated_attendees')->nullable()->after('department');
            $table->text('purpose')->nullable()->after('estimated_attendees');
            $table->text('requirements')->nullable()->after('purpose');
            $table->string('poster_url')->nullable()->after('requirements');
            $table->string('assigned_staff_id')->nullable()->after('poster_url');
            $table->foreign('assigned_staff_id')->references('id')->on('academic_staff')->nullOnDelete();
        });

        // Real status vocabulary alignment (docs/EKSIKLER.md aktivite/onay
        // workflow §6) — the earlier 'pending_review' value is renamed to
        // the explicitly requested 'pending_approval'; every other
        // existing row (draft/rejected/published) already matches the
        // requested set and is left as-is.
        DB::table('events')->where('workflow_status', 'pending_review')->update(['workflow_status' => 'pending_approval']);
    }

    public function down(): void
    {
        DB::table('events')->where('workflow_status', 'pending_approval')->update(['workflow_status' => 'pending_review']);
        Schema::table('events', function (Blueprint $table) {
            $table->dropForeign(['assigned_staff_id']);
            $table->dropColumn([
                'student_number', 'phone', 'faculty', 'department', 'end_time',
                'estimated_attendees', 'purpose', 'requirements', 'poster_url', 'assigned_staff_id',
            ]);
        });
    }
};
