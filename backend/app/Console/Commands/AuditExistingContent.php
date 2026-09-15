<?php

namespace App\Console\Commands;

use App\Models\FeedPost;
use App\Models\PostComment;
use App\Services\Moderation\TextPolicyEngine;
use Illuminate\Console\Command;

/**
 * Re-checks content that was published before a policy change.
 *
 * Tightening a rule does nothing about what is already on the timeline.
 * Racist posts that got through while the hate rules were incomplete stay
 * visible forever unless something goes back and looks — and asking a
 * moderator to reread every post by hand is how it never happens.
 *
 * Defaults to a dry run: it reports first and only touches anything when
 * explicitly asked, because a policy change plus a bulk delete is a bad
 * combination to trigger by accident.
 */
class AuditExistingContent extends Command
{
    protected $signature = 'moderation:audit-existing
                            {--apply : Hide the offending content instead of only reporting it}';

    protected $description = 'Re-run the current text policy over already-published posts and comments.';

    public function handle(): int
    {
        $engine = new TextPolicyEngine;
        $offenders = [];

        // Only what is actually visible. Content already hidden by a
        // previous run is not a finding — re-listing it would mean the
        // audit never reports clean and stops being worth running.
        foreach (FeedPost::includingUnmoderated()->where('workflow_status', '!=', 'rejected')->get() as $post) {
            $verdict = $engine->evaluate((string) $post->text);
            if ($verdict->blocksPublication()) {
                $offenders[] = ['post', $post, $verdict];
            }
        }

        foreach (PostComment::query()->get() as $comment) {
            $verdict = $engine->evaluate((string) $comment->text);
            if ($verdict->blocksPublication()) {
                $offenders[] = ['comment', $comment, $verdict];
            }
        }

        if ($offenders === []) {
            $this->info('Nothing published violates the current policy.');

            return self::SUCCESS;
        }

        $this->warn(count($offenders).' item(s) violate the current policy:');
        $this->table(
            ['Type', 'ID', 'Author', 'Category', 'Excerpt'],
            array_map(fn (array $row): array => [
                $row[0],
                $row[1]->id,
                $row[1]->name ?? $row[1]->user_id ?? '—',
                $row[2]->context,
                mb_substr((string) $row[1]->text, 0, 42),
            ], $offenders),
        );

        if (! $this->option('apply')) {
            $this->line('');
            $this->comment('Dry run. Re-run with --apply to hide these.');

            return self::SUCCESS;
        }

        $hidden = 0;
        foreach ($offenders as [$type, $model, $verdict]) {
            if ($type === 'post') {
                // Hidden rather than deleted: an appeal needs the original,
                // and a moderator has to be able to see what was actioned.
                $model->update([
                    'workflow_status' => 'rejected',
                    'review_note' => 'Otomatik denetim: '.$verdict->context,
                ]);
            } else {
                $model->delete();
            }
            $hidden++;
        }

        $this->info("Hid {$hidden} item(s).");

        return self::SUCCESS;
    }
}
