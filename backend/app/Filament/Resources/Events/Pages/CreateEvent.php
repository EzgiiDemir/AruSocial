<?php

namespace App\Filament\Resources\Events\Pages;

use App\Filament\Concerns\MintsPrefixedId;
use App\Filament\Resources\Events\EventResource;
use App\Services\AuditLogger;
use Filament\Resources\Pages\CreateRecord;

class CreateEvent extends CreateRecord
{
    use MintsPrefixedId;

    protected static string $resource = EventResource::class;

    protected function idPrefix(): string
    {
        return 'event';
    }

    protected function afterCreate(): void
    {
        AuditLogger::logAsCurrentUser('create', 'event', $this->record->title);
    }
}
