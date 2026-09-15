<?php

namespace App\Console\Commands;

use App\Models\FeedPost;
use App\Models\MediaItem;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Clears the Social timeline.
 *
 * Scope is deliberately narrow and was confirmed before this was written:
 * the posts on the Social page and the rows that exist only to describe
 * them. Comments, likes and saves go because the schema cascades them —
 * they have no meaning without their post.
 *
 * What it does **not** touch, on purpose:
 *
 *   - moderation events, reports, cases, appeals and strikes. These are the
 *     record of decisions taken about people. Deleting them would leave the
 *     university unable to answer "why was my content removed", which both
 *     app stores require an appeals process to be able to answer, and would
 *     erase the evidence behind any live disciplinary matter. They carry no
 *     foreign key into `feed_posts`, so they survive the delete on their own.
 *   - stories, chat and direct messages, place reviews, workshop
 *     collaboration posts. Different surfaces, not the timeline.
 *   - accounts. Nobody is deleted.
 *
 * A dry run by default. It deletes rows and bytes that cannot be brought
 * back except from the backup it writes, so the sequence is: run it, read
 * the manifest, run it again with --force.
 */
class PurgeSocialTimeline extends Command
{
    protected $signature = 'social:purge-timeline
                            {--force : Actually delete. Without this nothing is written}
                            {--backup-dir= : Where the backup goes (default storage/app/backups)}';

    protected $description = 'Back up and delete every post on the Social timeline';

    /** Tables emptied by the delete, in the order they are backed up. */
    private const CASCADED = ['post_comments', 'post_likes', 'saved_posts'];

    public function handle(): int
    {
        $dry = ! $this->option('force');

        // Without this the `approved-content` global scope hides posts held
        // for review or already rejected. Those are still rows on the
        // timeline's table, and leaving them would empty the screen while
        // the data stayed — the exact opposite of what was asked for.
        $posts = FeedPost::query()
            ->withoutGlobalScopes()
            ->orderBy('created_at')
            ->get();

        if ($posts->isEmpty()) {
            $this->info('The timeline is already empty. Nothing to do.');

            return self::SUCCESS;
        }

        $postIds = $posts->pluck('id')->all();

        $cascaded = [];
        foreach (self::CASCADED as $table) {
            $cascaded[$table] = DB::table($table)
                ->whereIn('post_id', $postIds)
                ->get()
                ->map(fn ($row) => (array) $row)
                ->all();
        }

        [$files, $mediaRows] = $this->attachedMedia($posts);

        $this->report($posts->count(), $cascaded, $files, $mediaRows);

        if ($dry) {
            $this->newLine();
            $this->warn('Dry run — nothing was written. Re-run with --force to delete.');

            return self::SUCCESS;
        }

        $backup = $this->writeBackup($posts, $cascaded, $files, $mediaRows);
        $this->info("Backup written to {$backup}");

        // Rows first, files second. If this fails halfway the files are
        // still on disk and still named by the backup, which is the
        // recoverable direction; deleting bytes first is not.
        $deletedRows = DB::transaction(function () use ($postIds, $mediaRows) {
            MediaItem::whereIn('id', array_column($mediaRows, 'id'))->delete();

            return FeedPost::withoutGlobalScopes()->whereIn('id', $postIds)->delete();
        });

        $deletedFiles = 0;
        $disk = Storage::disk(MediaItem::disk());
        foreach ($files as $path) {
            if ($disk->exists($path) && $disk->delete($path)) {
                $deletedFiles++;
            }
        }

        AuditLogger::log(
            'console',
            'purge',
            'social_timeline',
            sprintf('%d post(s), %d file(s); backup %s', $deletedRows, $deletedFiles, basename($backup)),
        );

        $this->newLine();
        $this->info(sprintf(
            'Deleted %d post(s) and %d media file(s). Cascaded rows went with them.',
            $deletedRows,
            $deletedFiles,
        ));

        $this->checkForOrphans($postIds);

        return self::SUCCESS;
    }

    /**
     * Media referenced by these posts and by nothing that survives.
     *
     * A file shared with a story, a place cover or a profile avatar has to
     * stay — the brief asks for no unused files left behind, not for
     * pictures to disappear out of screens that are not being cleared.
     *
     * @return array{0: list<string>, 1: list<array<string, mixed>>}
     */
    private function attachedMedia($posts): array
    {
        $urls = $posts->pluck('image_url')->filter()->unique()->values();
        if ($urls->isEmpty()) {
            return [[], []];
        }

        // `image_url` is the API route that serves the file, not a path:
        // `/api/v1/media/media-<uuid>/file`. The id is the only part that
        // links a post to its media row — matching on a filename finds the
        // literal segment "file" and therefore nothing at all.
        $ids = $urls
            ->map(function (string $url): ?string {
                preg_match('~/media/([^/]+)/file~', $url, $match);

                return $match[1] ?? null;
            })
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [[], []];
        }

        $rows = MediaItem::query()->whereIn('id', $ids->all())->get();

        $files = [];
        $keep = [];

        foreach ($rows as $row) {
            if ($this->referencedElsewhere($row, $posts->pluck('id')->all())) {
                $keep[] = $row->id;

                continue;
            }
            $files[] = $row->file_path;
        }

        return [
            array_values(array_unique($files)),
            $rows->reject(fn ($row) => in_array($row->id, $keep, true))
                ->map(fn ($row) => $row->toArray())
                ->values()
                ->all(),
        ];
    }

    /**
     * True when something other than these posts points at the file.
     *
     * `used_in` is the library's own note of where a file is shown. A row
     * that names anything beyond the posts being deleted is still in use.
     */
    private function referencedElsewhere(MediaItem $item, array $postIds): bool
    {
        $usedIn = $item->used_in;
        if (blank($usedIn)) {
            return false;
        }

        $references = is_array($usedIn) ? $usedIn : [$usedIn];

        foreach ($references as $reference) {
            $reference = (string) $reference;
            if ($reference !== '' && ! in_array($reference, $postIds, true)) {
                return true;
            }
        }

        return false;
    }

    private function report(int $posts, array $cascaded, array $files, array $mediaRows): void
    {
        $this->line("Posts on the timeline:            {$posts}");
        foreach ($cascaded as $table => $rows) {
            $this->line(sprintf('  %-30s %d (cascaded)', $table, count($rows)));
        }
        $this->line(sprintf('  %-30s %d', 'media_items rows', count($mediaRows)));
        $this->line(sprintf('  %-30s %d', 'media files on disk', count($files)));
        $this->newLine();
        $this->line('Untouched: moderation events/reports/cases/appeals, stories,');
        $this->line('chat, place reviews, collaboration posts, and all accounts.');
    }

    private function writeBackup($posts, array $cascaded, array $files, array $mediaRows): string
    {
        $root = $this->option('backup-dir')
            ?: storage_path('app/backups');

        $dir = $root.'/social-timeline-'.now()->format('Ymd-His');

        if (! is_dir($dir.'/media')) {
            mkdir($dir.'/media', 0775, true);
        }

        $write = function (string $name, array $data) use ($dir): void {
            file_put_contents(
                $dir.'/'.$name.'.json',
                json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            );
        };

        $write('feed_posts', $posts->map(fn ($p) => $p->toArray())->all());
        foreach ($cascaded as $table => $rows) {
            $write($table, $rows);
        }
        $write('media_items', $mediaRows);

        $disk = Storage::disk(MediaItem::disk());
        $copied = [];
        foreach ($files as $path) {
            if (! $disk->exists($path)) {
                continue;
            }
            $target = $dir.'/media/'.str_replace('/', '__', $path);
            file_put_contents($target, $disk->get($path));
            $copied[] = ['stored_at' => $path, 'backup_file' => basename($target)];
        }

        $write('manifest', [
            'taken_at' => now()->toIso8601String(),
            'scope' => 'social timeline posts and the rows that cascade from them',
            'not_included' => [
                'moderation events, reports, cases, appeals, strikes',
                'stories, chat and direct messages',
                'place reviews, workshop collaboration posts',
                'accounts',
            ],
            'counts' => array_merge(
                ['feed_posts' => $posts->count()],
                array_map('count', $cascaded),
                ['media_items' => count($mediaRows), 'media_files' => count($copied)],
            ),
            'media' => $copied,
        ]);

        return $dir;
    }

    /**
     * The brief asks for no orphaned rows or broken relationships left
     * behind, so this checks rather than asserting it in a comment.
     */
    private function checkForOrphans(array $postIds): void
    {
        $orphans = [];
        foreach (self::CASCADED as $table) {
            $left = DB::table($table)->whereIn('post_id', $postIds)->count();
            if ($left > 0) {
                $orphans[$table] = $left;
            }
        }

        if ($orphans === []) {
            $this->info('No orphaned rows left behind.');

            return;
        }

        foreach ($orphans as $table => $count) {
            $this->error("{$table} still has {$count} row(s) pointing at a deleted post.");
        }
    }
}
