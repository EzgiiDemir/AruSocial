<?php

namespace App\Filament\Resources\ShuttleRoutes\Pages;

use App\Filament\Concerns\MintsPrefixedId;
use App\Filament\Resources\ShuttleRoutes\ShuttleRouteResource;
use App\Services\AuditLogger;
use Filament\Resources\Pages\CreateRecord;

class CreateShuttleRoute extends CreateRecord
{
    use MintsPrefixedId;

    protected static string $resource = ShuttleRouteResource::class;

    protected function idPrefix(): string
    {
        return 'shuttle';
    }

    protected function afterCreate(): void
    {
        AuditLogger::logAsCurrentUser('create', 'shuttle', $this->record->name);
    }
}
