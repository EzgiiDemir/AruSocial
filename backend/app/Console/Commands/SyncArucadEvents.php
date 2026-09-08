<?php

namespace App\Console\Commands;

use App\Services\ArucadEventsSync;
use Illuminate\Console\Command;

class SyncArucadEvents extends Command
{
    protected $signature = 'campus:sync-events {--limit=50 : Maximum events to pull per source}';

    protected $description = 'Import published events from arucad.edu.tr into the campus app.';

    public function handle(): int
    {
        $summary = ArucadEventsSync::sync((int) $this->option('limit'));

        $this->table(
            ['Source', 'Imported', 'Skipped'],
            [[$summary['source'], $summary['imported'], $summary['skipped']]],
        );

        if ($summary['imported'] === 0) {
            $this->warn('No events imported — the university site currently publishes none.');
        }

        return self::SUCCESS;
    }
}
