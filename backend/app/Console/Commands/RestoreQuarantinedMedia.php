<?php

namespace App\Console\Commands;

use App\Models\MediaItem;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Puts quarantined media files back where they came from.
 *
 * `media:purge-orphans` moves rather than deletes precisely so that this
 * command can exist. "Untracked" means nothing in the database knows what
 * those bytes are — which also means nothing in the database can prove
 * they did not matter, and the only honest answer to "my pictures are
 * gone" is to put them back and then work out why.
 *
 * Restores to the path encoded in the quarantined filename: the purge
 * replaced `/` with `__`, so `media__abc.jpg` goes back to `media/abc.jpg`.
 * A file whose original path is occupied is left alone rather than
 * overwriting whatever is there now.
 */
class RestoreQuarantinedMedia extends Command
{
    protected $signature = 'media:restore-quarantine
                            {batch? : Which quarantine folder, e.g. 20260914-140052. Defaults to all}
                            {--force : Actually move the files back}';

    protected $description = 'Move quarantined media files back to their original paths';

    public function handle(): int
    {
        $disk = Storage::disk(MediaItem::disk());
        $batch = $this->argument('batch');

        $root = 'quarantine'.($batch !== null ? '/'.$batch : '');

        if (! $disk->exists($root)) {
            $this->error("Nothing at {$root}.");

            return self::FAILURE;
        }

        $files = $disk->allFiles($root);
        $this->line(sprintf('%d file(s) in %s', count($files), $root));

        if ($files === []) {
            return self::SUCCESS;
        }

        $planned = [];
        $occupied = [];

        foreach ($files as $path) {
            $original = str_replace('__', '/', basename($path));

            if ($disk->exists($original)) {
                $occupied[] = $original;

                continue;
            }

            $planned[$path] = $original;
        }

        $this->line(sprintf('  %d would be restored', count($planned)));
        $this->line(sprintf('  %d already exist at their original path and are left alone', count($occupied)));

        if (! $this->option('force')) {
            $this->newLine();
            $this->warn('Dry run — nothing moved. Re-run with --force.');
            foreach (array_slice($planned, 0, 5) as $from => $to) {
                $this->line("  {$from}  ->  {$to}");
            }

            return self::SUCCESS;
        }

        $restored = 0;
        foreach ($planned as $from => $to) {
            if ($disk->move($from, $to)) {
                $restored++;
            }
        }

        AuditLogger::log('console', 'restore', 'media_library', "{$restored} file(s) from {$root}");

        $this->newLine();
        $this->info("Restored {$restored} file(s).");

        return self::SUCCESS;
    }
}
