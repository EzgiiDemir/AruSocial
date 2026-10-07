<?php

namespace App\Filament\Resources\AiQueryConcepts\Pages;

use App\Filament\Resources\AiQueryConcepts\AiQueryConceptResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAiQueryConcept extends CreateRecord
{
    protected static string $resource = AiQueryConceptResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->user()?->email;

        return $data;
    }
}
