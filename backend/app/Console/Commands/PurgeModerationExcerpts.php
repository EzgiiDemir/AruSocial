<?php

namespace App\Console\Commands;

use App\Models\ModerationEvent;
use Illuminate\Console\Command;

/**
 * Deletes the stored copies of refused content once their appeal window
 * has closed.
 *
 * Every moderation decision keeps a short excerpt of what was refused, so
 * that "why was my post removed" has an answer while the student can still
 * appeal it. `excerpt_purge_after` is stamped on the row when the decision
 * is made, and has been since the feature shipped.
 *
 * Nothing ever deleted them. The column was written, the date passed, and
 * the text stayed — which means the app was holding copies of students'
 * refused posts indefinitely while the privacy policy said data is kept
 * only as long as it is needed.
 *
 * Only the excerpt goes. The decision, the category and the scores stay:
 * they are the audit trail, they contain no content, and without them the
 * university cannot answer a later question about what was decided.
 */
class PurgeModerationExcerpts extends Command
{
    protected $signature = 'moderation:purge-excerpts
                            {--force : Actually clear them. Without this nothing is written}';

    protected $description = 'Clear moderation excerpts whose retention window has passed';

    public function handle(): int
    {
        $due = ModerationEvent::query()
            ->whereNotNull('excerpt')
            ->whereNotNull('excerpt_purge_after')
            ->where('excerpt_purge_after', '<', now());

        $count = (clone $due)->count();
        $held = ModerationEvent::whereNotNull('excerpt')->count();

        $this->line("Excerpts held: {$held}");
        $this->line("Past their retention window: {$count}");

        if ($count === 0) {
            $this->info('Nothing to clear.');

            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            $this->warn('Dry run — nothing was cleared. Re-run with --force.');

            return self::SUCCESS;
        }

        // Nulled rather than the row deleted: the decision is the audit
        // trail and has to survive. Only the copy of the student's words
        // goes.
        $cleared = (clone $due)->update(['excerpt' => null]);

        $this->info("Cleared {$cleared} excerpt(s). Decisions and scores kept.");

        return self::SUCCESS;
    }
}
