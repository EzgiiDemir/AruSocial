<?php

namespace App\Console\Commands;

use App\Models\MediaItem;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

/**
 * Moves already-uploaded media off Laravel's public disk.
 *
 * Changing `filesystems.media_disk` to a private disk protects everything
 * uploaded from now on, but files written while the default was `public`
 * are still sitting under `storage/app/public` — which the
 * `public/storage` symlink serves at /storage/<path> with no auth and no
 * moderation check. Anyone who noted a URL keeps their copy until the
 * bytes actually move.
 *
 * Copy-then-verify-then-delete, so an interrupted run never destroys the
 * only copy. Re-running is safe: files already on the private disk are
 * skipped.
 */
class RelocateMediaToPrivateDisk extends Command
{
    protected $signature = 'media:relocate-private
        {--from=public : Disk the files are currently on}
        {--dry-run : List what would move without touching anything}';

    protected $description = 'Move media files from the public disk to the configured private media disk';

    public function handle(): int
    {
        $from = (string) $this->option('from');
        $to = MediaItem::disk();

        if ($from === $to) {
            $this->error("Source and destination are the same disk ('{$from}'). "
                .'Set filesystems.media_disk to a private disk first.');

            return self::FAILURE;
        }

        $source = Storage::disk($from);
        $target = Storage::disk($to);
        $dry = (bool) $this->option('dry-run');

        $moved = $skipped = $missing = $failed = 0;

        foreach (MediaItem::query()->cursor() as $item) {
            $path = (string) $item->file_path;
            if ($path === '') {
                continue;
            }

            if ($target->exists($path)) {
                $skipped++;
                // Already relocated. Still clear the public copy, or the
                // bypass stays open for exactly the files we thought we
                // had secured.
                if (! $dry && $source->exists($path)) {
                    $source->delete($path);
                }

                continue;
            }

            if (! $source->exists($path)) {
                $missing++;
                $this->warn("missing on '{$from}': {$path}");

                continue;
            }

            if ($dry) {
                $this->line("would move: {$path}");
                $moved++;

                continue;
            }

            $stream = $source->readStream($path);
            if ($stream === null || $stream === false) {
                $failed++;
                $this->error("unreadable: {$path}");

                continue;
            }

            $target->writeStream($path, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }

            // Only drop the original once the copy is provably there.
            if (! $target->exists($path)) {
                $failed++;
                $this->error("copy failed, original kept: {$path}");

                continue;
            }

            $source->delete($path);
            $moved++;
        }

        $orphans = $this->sweepOrphans($source, $target, $dry);

        $verb = $dry ? 'would move' : 'moved';
        $this->info("{$verb}: {$moved}, already private: {$skipped}, missing: {$missing}, "
            ."orphans {$verb}: {$orphans}, failed: {$failed}");

        if (! $dry && $failed === 0) {
            $this->info("Media now served only through /api/v1/media/{id}/file on disk '{$to}'.");
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Files under media/ with no MediaItem row.
     *
     * These have never been through moderation — that is precisely what
     * having no row means — and the API already refuses to serve them.
     * But the public/storage symlink does not consult the database, so on
     * the public disk they stay readable by direct URL. They are moved
     * rather than deleted: their provenance is unknown, and unreferenced
     * is not the same as worthless.
     */
    private function sweepOrphans(
        Filesystem $source,
        Filesystem $target,
        bool $dry,
    ): int {
        $known = MediaItem::query()->pluck('file_path')->filter()->flip();
        $count = 0;

        foreach ($source->allFiles('media') as $path) {
            if ($known->has($path)) {
                continue;
            }
            if ($dry) {
                $this->line("would move orphan: {$path}");
                $count++;

                continue;
            }

            $stream = $source->readStream($path);
            if ($stream === null || $stream === false) {
                $this->warn("unreadable orphan, left in place: {$path}");

                continue;
            }
            $target->writeStream('orphaned/'.$path, $stream);
            if (is_resource($stream)) {
                fclose($stream);
            }
            if ($target->exists('orphaned/'.$path)) {
                $source->delete($path);
                $count++;
            }
        }

        return $count;
    }
}
