<?php

namespace App\Filament\Resources\AiEvaluationCases\Pages;

use App\Filament\Resources\AiEvaluationCases\AiEvaluationCaseResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAiEvaluationCase extends CreateRecord
{
    protected static string $resource = AiEvaluationCaseResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->user()?->email;

        return $data;
    }
}
