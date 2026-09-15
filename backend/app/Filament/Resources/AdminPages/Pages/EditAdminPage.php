<?php

namespace App\Filament\Resources\AdminPages\Pages;

use App\Filament\Resources\AdminPages\AdminPageResource;
use App\Services\AuditLogger;
use App\Services\PageVersionRecorder;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditAdminPage extends EditRecord
{
    protected static string $resource = AdminPageResource::class;

    protected function getHeaderActions(): array
    {
        return [ViewAction::make()->label(__('panel.pages.preview')), DeleteAction::make(), RestoreAction::make(), ForceDeleteAction::make()];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['updated_at'] = now();
        $data['updated_by'] = auth()->user()?->email ?? 'admin';

        return $data;
    }

    protected function afterSave(): void
    {
        PageVersionRecorder::record($this->record);
        AuditLogger::logAsCurrentUser('update', 'page', $this->record->title);
    }
}
