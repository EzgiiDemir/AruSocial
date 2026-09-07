<?php

namespace App\Console\Commands;

use App\Services\CampusDirectory360Sync;
use Illuminate\Console\Command;

class SyncCampusDirectory360 extends Command
{
    protected $signature = 'campus:sync-360-directory';

    protected $description = 'Sync ARUCAD 360° room directory and navigation links into the local API.';

    public function handle(CampusDirectory360Sync $sync): int
    {
        try {
            $summary = $sync->sync();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Campuses', 'Buildings', 'Rooms', '360 tours', 'Places updated', 'Service links', 'Coordinates received'],
            [[
                $summary['campuses'],
                $summary['buildings'],
                $summary['roomsSynced'],
                $summary['roomsWithTours'],
                $summary['placesUpdated'],
                $summary['serviceLinksUpdated'] ?? 0,
                $summary['coordinatesReceived'],
            ]],
        );

        return self::SUCCESS;
    }
}
