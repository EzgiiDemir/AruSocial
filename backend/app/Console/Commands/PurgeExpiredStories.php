<?php

namespace App\Console\Commands;

use App\Models\Story;
use Illuminate\Console\Command;

/**
 * Deletes stories once their 24 hours are up.
 *
 * Reading was already time-filtered, so an expired story was invisible —
 * but it was still there: the row, its view records, and everything the
 * text held. "Ephemeral" that only means "hidden" is a promise the product
 * makes and the database does not keep, and the gap grows forever.
 *
 * story_views cascades on the foreign key, so removing the story removes
 * who watched it too.
 *
 * Uploaded images are deliberately left alone. A story attaches a picture
 * from the student's own gallery, and that gallery item has its own
 * lifecycle — deleting it here would quietly destroy a photo the student
 * still owns and never asked to lose.
 */
class PurgeExpiredStories extends Command
{
    protected $signature = 'stories:purge-expired {--dry-run : Report what would be deleted without deleting it}';

    protected $description = 'Delete stories older than 24 hours';

    public function handle(): int
    {
        $cutoff = now()->subDay();
        $expired = Story::includingUnmoderated()->where('created_at', '<', $cutoff);

        $count = (clone $expired)->count();

        if ($this->option('dry-run')) {
            $this->info("{$count} expired story/stories would be deleted (older than {$cutoff}).");

            return self::SUCCESS;
        }

        // delete() on the builder, not a loop: the rows carry no model
        // events worth firing and the cascade handles story_views.
        $deleted = $expired->delete();
        $this->info("Deleted {$deleted} expired story/stories.");

        return self::SUCCESS;
    }
}
