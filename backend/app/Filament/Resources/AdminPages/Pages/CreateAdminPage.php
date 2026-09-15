<?php

namespace App\Filament\Resources\AdminPages\Pages;

use App\Filament\Concerns\MintsPrefixedId;
use App\Filament\Resources\AdminPages\AdminPageResource;
use App\Services\AuditLogger;
use App\Services\PageVersionRecorder;
use Filament\Resources\Pages\CreateRecord;

class CreateAdminPage extends CreateRecord
{
    use MintsPrefixedId;

    protected static string $resource = AdminPageResource::class;

    protected function idPrefix(): string
    {
        return 'page';
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data = $this->mintId($data);
        $data['updated_at'] = now();
        $data['updated_by'] = auth()->user()?->email ?? 'admin';

        return $data;
    }

    protected function afterCreate(): void
    {
        PageVersionRecorder::record($this->record);
        AuditLogger::logAsCurrentUser('create', 'page', $this->record->title);
    }
}
