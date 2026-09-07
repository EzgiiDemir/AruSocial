<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Two uniqueness rules the application already *tries* to enforce in PHP,
// but a concurrent double-submit races the existence check:
//
//   1. One booked appointment per staff slot.
//   2. One open participation application per (user, target).
//
// Partial unique indexes (WHERE …) keep cancelled/rejected rows from
// blocking a later legitimate retry. SQLite and PostgreSQL both accept
// this syntax; the live schema dump is regenerated separately.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS appointments_booked_slot_unique
             ON appointments (staff_profile_id, slot_date, start_time)
             WHERE status = \'booked\''
        );

        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS participation_applications_open_unique
             ON participation_applications (user_id, target_type, target_id)
             WHERE status IN (
                \'preview_submitted\',
                \'detail_form_pending\',
                \'detail_form_submitted\',
                \'under_review\',
                \'revision_required\'
             )'
        );
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS appointments_booked_slot_unique');
            DB::statement('DROP INDEX IF EXISTS participation_applications_open_unique');

            return;
        }

        DB::statement('DROP INDEX IF EXISTS appointments_booked_slot_unique');
        DB::statement('DROP INDEX IF EXISTS participation_applications_open_unique');
    }
};
