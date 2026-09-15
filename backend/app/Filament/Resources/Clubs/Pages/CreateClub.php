<?php

namespace App\Filament\Resources\Clubs\Pages;

use App\Filament\Concerns\MintsPrefixedId;
use App\Filament\Resources\Clubs\ClubResource;
use App\Services\AuditLogger;
use Filament\Resources\Pages\CreateRecord;

class CreateClub extends CreateRecord
{
    use MintsPrefixedId;

    protected static string $resource = ClubResource::class;

    protected function idPrefix(): string
    {
        return 'club';
    }

    protected function afterCreate(): void
    {
        AuditLogger::logAsCurrentUser('create', 'club', $this->record->name);
    }
}
