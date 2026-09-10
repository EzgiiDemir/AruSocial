<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The workflow half of moderation: cases, violations, appeals, audit.
 *
 * Detection already exists — ContentModerator produces verdicts and
 * moderation_events records them. What was missing is everything that
 * happens after a verdict: a queue a human can work, a record of what was
 * actually confirmed, a way for a student to contest it, and a trail
 * showing who decided what.
 *
 * Deliberately portable (no enums, no database-specific types) because the
 * app is moving from SQLite to PostgreSQL and these tables must survive the
 * copy unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * A case is the unit of human work. Automatic verdicts, user
         * reports and appeals all converge onto one, so a moderator sees a
         * single item rather than the same photo arriving three times from
         * three sources.
         */
        Schema::create('moderation_cases', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('content_type', 40);              // post, comment, story, image, video, profile
            $table->string('content_id')->nullable();
            $table->foreignId('user_id')->nullable()         // author of the content
                ->constrained('users')->nullOnDelete();
            $table->string('source', 20);                    // automatic | user_report | appeal | system
            $table->unsignedSmallInteger('priority')->default(50);   // 0 highest
            $table->string('status', 20)->default('open');   // open | reviewing | resolved
            $table->string('decision', 30)->nullable();      // approve | remove | warn | restrict | suspend | ban | escalate
            $table->string('recommendation', 30)->nullable();// what the machine suggested, for override tracking
            $table->uuid('moderation_event_id')->nullable(); // the automatic evidence, when there is any
            $table->unsignedInteger('report_count')->default(0);
            $table->foreignId('assigned_moderator_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->text('resolution_note')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            // The queue is read as "most urgent, oldest first".
            $table->index(['status', 'priority', 'created_at']);
            $table->index(['content_type', 'content_id']);
            $table->index('user_id');
        });

        /*
         * Only confirmed violations. A machine verdict is evidence, not a
         * violation — nothing here is written by a model, only by a
         * moderator decision or an explicitly configured auto-confirm rule.
         *
         * `idempotency_key` is what stops a retried job or a double-clicked
         * button from punishing someone twice for one act.
         */
        Schema::create('user_violations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('moderation_case_id')->nullable();
            $table->string('category', 40);                  // hate, harassment, threat, sexual, spam…
            $table->string('severity', 20);                  // minor | serious | severe | critical
            $table->boolean('confirmed')->default(false);
            $table->unsignedSmallInteger('points')->default(0);
            $table->string('action_taken', 40)->nullable();  // warning, restriction, suspension, ban
            $table->foreignId('decided_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('idempotency_key', 120)->unique();
            $table->timestamp('expires_at')->nullable();     // points decay; an old mistake stops compounding
            $table->timestamps();

            $table->index(['user_id', 'confirmed', 'expires_at']);
        });

        /*
         * An appeal reopens a case for a human. It deliberately carries no
         * "auto_decision" column: re-running the same model and calling the
         * result a review is the one thing this table exists to prevent.
         */
        Schema::create('moderation_appeals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('moderation_case_id');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('original_decision', 30);
            $table->text('reason');
            $table->string('status', 20)->default('open');   // open | reviewing | upheld | overturned
            $table->foreignId('reviewed_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->text('review_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            // One open appeal per case per user; a rejected appeal does not
            // become an unlimited retry loop.
            $table->unique(['moderation_case_id', 'user_id']);
            $table->index(['status', 'created_at']);
        });

        /*
         * Append-only. Nothing in the application updates or deletes a row
         * here — the question "who changed this, when, why, and from what
         * to what" must stay answerable years later.
         */
        Schema::create('moderation_audit_log', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('actor_type', 20);                // moderator | system | user
            $table->foreignId('actor_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('action', 40);
            $table->string('target_type', 40);
            $table->string('target_id')->nullable();
            $table->uuid('moderation_case_id')->nullable();
            $table->text('reason')->nullable();
            $table->string('previous_state', 40)->nullable();
            $table->string('new_state', 40)->nullable();
            // Context, never a copy of the media itself.
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['target_type', 'target_id']);
            $table->index(['actor_id', 'created_at']);
            $table->index('moderation_case_id');
        });

        /*
         * The existing reports table predates any of this: it has no
         * reporter, so "three independent students reported this" and "one
         * student clicked report three times" were indistinguishable, and
         * duplicate protection was impossible. Columns are added rather
         * than the table replaced, because ten call sites read it today.
         */
        Schema::table('moderation_reports', function (Blueprint $table): void {
            $table->foreignId('reporter_user_id')->nullable()
                ->constrained('users')->cascadeOnDelete();
            $table->string('target_type', 40)->nullable();   // normalized successor to `kind`
            $table->string('reason_code', 40)->nullable();   // harassment, hate, threat, …
            $table->text('description')->nullable();
            $table->string('status', 20)->default('open');   // open | reviewing | resolved | rejected
            $table->uuid('moderation_case_id')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['target_type', 'target_id']);
            // One report per person per target per reason. Reporting the
            // same post again for a different reason is legitimate;
            // clicking the same button ten times is not a louder signal.
            $table->unique(
                ['reporter_user_id', 'target_type', 'target_id', 'reason_code'],
                'reports_one_per_reporter_reason'
            );
        });

        /*
         * Evidence rows must say which model and which policy produced the
         * verdict, or a threshold change makes every historical decision
         * unexplainable.
         */
        Schema::table('moderation_events', function (Blueprint $table): void {
            $table->string('model_version', 60)->nullable();
            $table->string('policy_version', 40)->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->uuid('moderation_case_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('moderation_events', function (Blueprint $table): void {
            $table->dropColumn(['model_version', 'policy_version', 'latency_ms', 'moderation_case_id']);
        });

        Schema::table('moderation_reports', function (Blueprint $table): void {
            $table->dropUnique('reports_one_per_reporter_reason');
            $table->dropIndex(['status', 'created_at']);
            $table->dropIndex(['target_type', 'target_id']);
            $table->dropConstrainedForeignId('reporter_user_id');
            $table->dropColumn([
                'target_type', 'reason_code', 'description', 'status',
                'moderation_case_id', 'created_at', 'updated_at',
            ]);
        });

        Schema::dropIfExists('moderation_audit_log');
        Schema::dropIfExists('moderation_appeals');
        Schema::dropIfExists('user_violations');
        Schema::dropIfExists('moderation_cases');
    }
};
