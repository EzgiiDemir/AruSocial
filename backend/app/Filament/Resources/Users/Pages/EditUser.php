<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Services\AuditLogger;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [ViewAction::make()];
    }

    protected function afterSave(): void
    {
        AuditLogger::logAsCurrentUser('update', 'user', $this->record->email);
    }
}
