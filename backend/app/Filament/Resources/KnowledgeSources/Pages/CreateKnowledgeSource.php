<?php

namespace App\Filament\Resources\KnowledgeSources\Pages;

use App\Filament\Resources\KnowledgeSources\KnowledgeSourceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateKnowledgeSource extends CreateRecord
{
    protected static string $resource = KnowledgeSourceResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->user()?->email;

        return $data;
    }
}
