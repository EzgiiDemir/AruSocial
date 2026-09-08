<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit trail for every automated moderation decision, plus the ban state
 * the strike ladder needs.
 *
 * Only an excerpt of the offending content is kept, and only long enough
 * for an appeal to be reviewed (see services.moderation.retain_excerpt_days)
 * — an indefinite archive of the worst thing every student ever typed is not
 * something this system should hold.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('moderation_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('user_id')->index();

            // What was submitted, and from where.
            $table->string('content_type', 40);          // post, comment, story, bio, image, video…
            $table->string('source_feature', 60);        // feed.store, profile.bio, chat.send…
            $table->string('content_id')->nullable();    // set once the row exists

            // The decision.
            $table->string('action', 30);                // allowed, warned, rejected, review
            $table->boolean('flagged')->default(false);
            $table->json('categories')->nullable();
            $table->json('category_scores')->nullable();
            $table->string('decided_by', 20)->default('openai'); // openai, local, both, unavailable

            // The consequence.
            $table->unsignedInteger('strike_number')->nullable();
            $table->string('penalty', 30)->nullable();   // none, warning, ban
            $table->timestamp('banned_until')->nullable();

            // Provenance + privacy-bounded excerpt.
            $table->string('moderation_provider', 40)->default('openai');
            $table->string('moderation_model', 60)->nullable();
            $table->text('excerpt')->nullable();
            $table->timestamp('excerpt_purge_after')->nullable();

            // Idempotency: one submission must not become two strikes even
            // if the client retries or double-taps.
            $table->string('submission_hash', 64)->nullable()->index();

            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            // `strikes` and `banned_at` already exist. These add the
            // expiring-ban half of the ladder.
            if (! Schema::hasColumn('users', 'banned_until')) {
                $table->timestamp('banned_until')->nullable()->after('banned_at');
            }
            if (! Schema::hasColumn('users', 'last_violation_at')) {
                $table->timestamp('last_violation_at')->nullable()->after('banned_until');
            }
            if (! Schema::hasColumn('users', 'moderation_status')) {
                $table->string('moderation_status', 20)->default('clear')->after('last_violation_at');
            }
            if (! Schema::hasColumn('users', 'moderation_reason')) {
                $table->string('moderation_reason')->nullable()->after('moderation_status');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('moderation_events');

        Schema::table('users', function (Blueprint $table) {
            foreach (['banned_until', 'last_violation_at', 'moderation_status', 'moderation_reason'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
