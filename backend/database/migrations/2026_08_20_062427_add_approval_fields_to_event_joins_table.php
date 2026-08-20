<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Real teacher/club-manager attendance approval (docs/EKSIKLER.md §5):
// joining an event already triggers the real 2-stage email (club
// organizer + student form) — this adds the missing last step, where the
// organizer actually reviews the roster and approves each student's real
// attendance from the admin panel, instead of a join silently counting
// as "attending" with no human check at all.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_joins', function (Blueprint $table) {
            $table->timestamp('approved_at')->nullable()->after('joined_at');
            $table->string('approved_by')->nullable()->after('approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('event_joins', function (Blueprint $table) {
            $table->dropColumn(['approved_at', 'approved_by']);
        });
    }
};
