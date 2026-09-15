<?php

namespace App\Console\Commands;

use App\Models\MediaItem;
use App\Services\AuditLogger;
use App\Services\Media\MediaAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Removes what `media:audit` found, after somebody has read it.
 *
 * Dry run by default, and it moves rather than deletes: untracked files go
 * to `quarantine/<timestamp>/` on the same disk instead of being unlinked.
 * The whole premise of "untracked" is that nothing in the database knows
 * what these bytes are, which means nothing in the database can tell you
 * whether one of them mattered. Moving is reversible with `mv`; deleting a
 * thousand files on the strength of a heuristic is not.
 *
 * Broken rows — a row whose file is gone — are soft-deleted rather than
 * removed. The app stops rendering a frame that will never load, and the
 * row survives in case the file comes back from a backup.
 */
class PurgeOrphanedMedia extends Command
{
    protected $signature = 'media:purge-orphans
                            {--force : Actually move files and soft-delete rows}
                            {--delete : Delete untracked files outright instead of quarantining them}';

    protected $description = 'Quarantine untracked media files and soft-delete rows whose file is gone';

    public function handle(): int
    {
        $dry = ! $this->option('force');
        $report = MediaAudit::run();

        $untracked = $report['untracked'];
        $broken = $report['broken'];

        $this->line(sprintf(
            '%d untracked file(s) (%s), %d broken row(s).',
            count($untracked),
            MediaAudit::humanBytes($report['bytes_untracked']),
            count($broken),
        ));

        if ($untracked === [] && $broken === []) {
            $this->info('Nothing to do.');

            return self::SUCCESS;
        }

        if ($dry) {
            $this->newLine();
            $this->warn('Dry run — nothing was changed. Re-run with --force.');
            $this->line($this->option('delete')
                ? 'With --force --delete the untracked files would be removed permanently.'
                : 'With --force the untracked files would be MOVED to quarantine, not deleted.');

            return self::SUCCESS;
        }

        $disk = Storage::disk(MediaItem::disk());
        $quarantine = 'quarantine/'.now()->format('Ymd-His');

        $moved = 0;
        $removed = 0;

        foreach ($untracked as $path) {
            if (! $disk->exists($path)) {
                continue;
            }

            if ($this->option('delete')) {
                $disk->delete($path) && $removed++;

                continue;
            }

            $disk->move($path, $quarantine.'/'.str_replace('/', '__', $path)) && $moved++;
        }

        $softDeleted = 0;
        foreach ($broken as $row) {
            $item = MediaItem::find($row['id']);
            if ($item !== null) {
                $item->delete();
                $softDeleted++;
            }
        }

        AuditLogger::log(
            'console',
            'purge',
            'media_library',
            sprintf('%d quarantined, %d deleted, %d broken rows hidden', $moved, $removed, $softDeleted),
        );

        $this->newLine();
        if ($moved > 0) {
            $this->info("Moved {$moved} file(s) to {$quarantine}. Delete that folder once you are satisfied.");
        }
        if ($removed > 0) {
            $this->info("Deleted {$removed} file(s) permanently.");
        }
        $this->info("Soft-deleted {$softDeleted} row(s) whose file was missing.");

        return self::SUCCESS;
    }
}
