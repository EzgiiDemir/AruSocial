<?php

namespace App\Console\Commands;

use App\Models\MediaItem;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Removes video left behind by the retired video feature.
 *
 * Video was removed from the product on 14 September 2026. Nothing can
 * upload one any more, but a deployment that ran the old build still has
 * the files, and the privacy policy is explicit: media is deleted when the
 * process it belonged to ends (`docs/legal/privacy.md`, "Saklama"). A
 * feature that no longer exists has no process left.
 *
 * A command rather than a migration, and a dry run by default, because
 * this deletes bytes that cannot be recovered and every environment holds
 * a different amount of them. Run it, read what it says it will do, then
 * run it again with --force.
 *
 * On this development database it reported 128 orphaned files and 0 rows:
 * the media_items table had no video in it at all, so the files were
 * left over from testing rather than anything a student uploaded.
 */
class PurgeVideoMedia extends Command
{
    protected $signature = 'media:purge-video
                            {--force : Actually delete. Without this nothing is written}';

    protected $description = 'Delete stored video files and rows left by the retired video feature';

    /** What the old upload path wrote, and what it wrote it as. */
    private const VIDEO_DIR = 'media/video';

    private const VIDEO_MIME_PREFIX = 'video/';

    public function handle(): int
    {
        $dry = ! $this->option('force');

        $rows = MediaItem::query()
            ->where('mime_type', 'like', self::VIDEO_MIME_PREFIX.'%')
            ->orWhere('file_path', 'like', self::VIDEO_DIR.'/%')
            ->get();

        $disk = Storage::disk(MediaItem::disk());
        $files = $disk->exists(self::VIDEO_DIR)
            ? $disk->files(self::VIDEO_DIR)
            : [];

        $this->line(sprintf('%d media_items row(s) and %d stored file(s) match.',
            $rows->count(), count($files)));

        if ($rows->isEmpty() && $files === []) {
            $this->info('Nothing to purge.');

            return self::SUCCESS;
        }

        if ($dry) {
            foreach ($rows->take(10) as $row) {
                $this->line('  row   '.$row->id.'  '.$row->file_path);
            }
            foreach (array_slice($files, 0, 10) as $file) {
                $this->line('  file  '.$file);
            }
            $this->newLine();
            $this->warn('Dry run. Re-run with --force to delete.');

            return self::SUCCESS;
        }

        $deletedFiles = 0;
        foreach ($files as $file) {
            if ($disk->delete($file)) {
                $deletedFiles++;
            }
        }

        // The row's own file is deleted too — it may sit outside the video
        // directory if an older build stored it elsewhere.
        $deletedRows = 0;
        foreach ($rows as $row) {
            $path = (string) $row->file_path;
            if ($path !== '' && $disk->exists($path)) {
                $disk->delete($path);
            }
            $row->delete();
            $deletedRows++;
        }

        // Deleting student media is exactly the kind of thing that has to
        // be answerable afterwards, even when the answer is "a retired
        // feature was cleaned up".
        AuditLogger::log('system', 'media_purge', 'media', sprintf(
            'Retired video feature: %d row(s), %d file(s) deleted.',
            $deletedRows, $deletedFiles,
        ));

        $this->info(sprintf('Deleted %d row(s) and %d file(s).',
            $deletedRows, $deletedFiles));

        return self::SUCCESS;
    }
}
