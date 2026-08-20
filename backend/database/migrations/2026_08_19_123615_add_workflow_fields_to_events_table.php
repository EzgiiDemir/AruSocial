<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Supports "Kendi Aktiviteni Oluştur" (docs/EKSIKLER.md §5): a
        // student-authored event goes through a real
        // draft -> pending_review -> approved/rejected -> published ->
        // completed workflow instead of admin-only instant-publish. The
        // existing `draft` boolean stays as-is (it's what CampusEventDto
        // already reads) — `workflow_status` is the richer state machine
        // layered on top, and only meaningful when `created_by_user_id`
        // is set (a student-created activity); admin-created events skip
        // straight to 'published' the moment they're not `draft`.
        Schema::table('events', function (Blueprint $table) {
            $table->foreignId('created_by_user_id')->nullable()->after('id')
                ->constrained('users')->nullOnDelete();
            $table->string('workflow_status')->default('published')->after('draft');
            $table->text('review_note')->nullable()->after('workflow_status');
            $table->string('place_id')->nullable()->after('place_name');
            $table->foreign('place_id')->references('id')->on('places')->nullOnDelete();
            $table->string('academic_year_id')->nullable()->after('place_id');
            $table->foreign('academic_year_id')->references('id')->on('academic_years')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropForeign(['created_by_user_id']);
            $table->dropForeign(['place_id']);
            $table->dropForeign(['academic_year_id']);
            $table->dropColumn(['created_by_user_id', 'workflow_status', 'review_note', 'place_id', 'academic_year_id']);
        });
    }
};
