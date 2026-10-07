<?php

namespace App\Filament\Resources\AiQueryConcepts\Pages;

use App\Filament\Resources\AiQueryConcepts\AiQueryConceptResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAiQueryConcept extends EditRecord
{
    protected static string $resource = AiQueryConceptResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
