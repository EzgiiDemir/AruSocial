<?php

namespace App\Filament\Resources\Places\Pages;

use App\Filament\Concerns\MintsPrefixedId;
use App\Filament\Resources\Places\PlaceResource;
use App\Services\AuditLogger;
use Filament\Resources\Pages\CreateRecord;

class CreatePlace extends CreateRecord
{
    use MintsPrefixedId;

    protected static string $resource = PlaceResource::class;

    protected function idPrefix(): string
    {
        return 'place';
    }

    protected function afterCreate(): void
    {
        AuditLogger::logAsCurrentUser('create', 'place', $this->record->name);
    }
}
