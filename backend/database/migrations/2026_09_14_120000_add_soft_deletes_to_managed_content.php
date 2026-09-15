<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gives the panels a delete that can be taken back.
 *
 * The Admin and Trainer panels let a member of staff remove a building, an
 * event, a shuttle route or an account. Before this, `delete()` meant the
 * row was gone: a mis-click on the wrong row of a 300-row table destroyed
 * content that had no other copy, and the only recovery was a database
 * restore that would also roll back everything else since the backup.
 *
 * Only the tables the panels actually manage are listed. Ledger-style
 * tables are deliberately excluded and stay hard-deletable or immutable:
 * an audit log, a moderation event, a strike or a revision is a record of
 * something that happened, and a row you can hide is not a record. Junction
 * and per-user rows (likes, follows, views, tokens) are excluded too —
 * nobody restores a like, and `deleted_at` on those tables would cost an
 * index on hot read paths for nothing.
 *
 * `users` is deliberately *not* here. Deleting an account is supposed to
 * take the person's posts, stories, club memberships and onboarding
 * progress with it, and that erasure is done by `ON DELETE CASCADE` in the
 * schema. A soft delete never issues a DELETE, so none of those cascades
 * would fire: the account would vanish from the admin listing while the
 * student's content stayed on the feed. Reversible lockout is what the
 * ban/enforcement path is for.
 */
return new class extends Migration
{
    /**
     * Tables the panels create, edit and delete rows in.
     */
    private const TABLES = [
        'places',
        'events',
        'clubs',
        'services',
        'shuttle_routes',
        'sports',
        'food_venues',
        'food_daily_menus',
        'career_opportunities',
        'admin_pages',
        'surveys',
        'onboarding_steps',
        'staff_profiles',
        'directory_entries',
        'quests',
        'achievement_definitions',
        'workshop_equipment_items',
        'academic_years',
        'event_participation_types',
        'application_questions',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'deleted_at')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->softDeletes();

                // Every panel listing and every public API read filters on
                // `deleted_at IS NULL`. Without an index that predicate is a
                // sequential scan on tables the app reads constantly.
                $blueprint->index('deleted_at');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'deleted_at')) {
                continue;
            }

            // Rolling back throws away which rows were deleted, so anything
            // soft-deleted comes back visible rather than being destroyed.
            // Losing the fact of a deletion is recoverable; losing the row
            // is not.
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropIndex($table.'_deleted_at_index');
                $blueprint->dropSoftDeletes();
            });
        }
    }
};
