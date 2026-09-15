<?php

namespace App\Console\Commands;

use App\Services\Media\MediaAudit;
use Illuminate\Console\Command;

/**
 * Reports what is wrong with the media library without changing anything.
 *
 * Deliberately separate from the command that acts on it: finding a
 * thousand stray files and deleting a thousand stray files are two
 * decisions, and a single command that does both invites the second one to
 * be made by accident.
 */
class MediaAuditCommand extends Command
{
    protected $signature = 'media:audit {--list : Print every path rather than a sample}';

    protected $description = 'Report untracked files, broken rows and unreferenced media';

    public function handle(): int
    {
        $report = MediaAudit::run();

        $this->line(sprintf('media_items rows : %d', $report['rows']));
        $this->line(sprintf('files on disk    : %d', $report['files']));
        $this->newLine();

        $this->line(sprintf(
            'Untracked files  : %d  (%s)  — bytes with no row; nothing can show or delete them',
            count($report['untracked']),
            MediaAudit::humanBytes($report['bytes_untracked']),
        ));
        $this->line(sprintf(
            'Broken rows      : %d  — a row whose file is gone; the app renders a frame that never loads',
            count($report['broken']),
        ));
        $this->line(sprintf(
            'Unreferenced     : %d  — a row with a file that nothing uses',
            count($report['unreferenced']),
        ));

        $show = $this->option('list') ? PHP_INT_MAX : 8;

        foreach (['untracked' => 'UNTRACKED FILES', 'broken' => 'BROKEN ROWS', 'unreferenced' => 'UNREFERENCED ROWS'] as $key => $title) {
            if ($report[$key] === []) {
                continue;
            }

            $this->newLine();
            $this->line($title);
            foreach (array_slice($report[$key], 0, $show) as $item) {
                $this->line('  '.(is_array($item) ? $item['id'].'  '.$item['path'] : $item));
            }
            if (! $this->option('list') && count($report[$key]) > $show) {
                $this->line(sprintf('  … and %d more (--list for all)', count($report[$key]) - $show));
            }
        }

        $this->newLine();
        $this->comment('Nothing was changed. `media:purge-orphans` acts on this.');

        return self::SUCCESS;
    }
}
