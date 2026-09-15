<?php

namespace App\Filament\Trainer\Resources\Events\Pages;

use App\Filament\Trainer\Resources\Events\EventResource;
use App\Services\AuditLogger;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditEvent extends EditRecord
{
    protected static string $resource = EventResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        AuditLogger::logAsCurrentUser('update', 'event', $this->record->title);
    }
}
