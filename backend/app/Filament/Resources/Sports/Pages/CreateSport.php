<?php

namespace App\Filament\Resources\Sports\Pages;

use App\Filament\Concerns\MintsPrefixedId;
use App\Filament\Resources\Sports\SportResource;
use App\Services\AuditLogger;
use Filament\Resources\Pages\CreateRecord;

class CreateSport extends CreateRecord
{
    use MintsPrefixedId;

    protected static string $resource = SportResource::class;

    protected function idPrefix(): string
    {
        return 'sport';
    }

    protected function afterCreate(): void
    {
        AuditLogger::logAsCurrentUser('create', 'sport', $this->record->name);
    }
}
