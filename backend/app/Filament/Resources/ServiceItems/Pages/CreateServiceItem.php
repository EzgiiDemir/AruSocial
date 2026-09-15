<?php

namespace App\Filament\Resources\ServiceItems\Pages;

use App\Filament\Concerns\MintsPrefixedId;
use App\Filament\Resources\ServiceItems\ServiceItemResource;
use App\Services\AuditLogger;
use Filament\Resources\Pages\CreateRecord;

class CreateServiceItem extends CreateRecord
{
    use MintsPrefixedId;

    protected static string $resource = ServiceItemResource::class;

    protected function idPrefix(): string
    {
        return 'service';
    }

    protected function afterCreate(): void
    {
        AuditLogger::logAsCurrentUser('create', 'service', $this->record->title);
    }
}
