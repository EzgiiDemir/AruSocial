<?php

namespace App\Filament\Resources\ShuttleRoutes\Pages;

use App\Filament\Resources\ShuttleRoutes\ShuttleRouteResource;
use App\Services\AuditLogger;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditShuttleRoute extends EditRecord
{
    protected static string $resource = ShuttleRouteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }

    protected function afterSave(): void
    {
        AuditLogger::logAsCurrentUser('update', 'shuttle', $this->record->name);
    }
}
