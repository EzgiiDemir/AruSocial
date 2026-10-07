<?php

namespace App\Filament\Resources\AiQueryConcepts\Pages;

use App\Filament\Resources\AiQueryConcepts\AiQueryConceptResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAiQueryConcepts extends ListRecords
{
    protected static string $resource = AiQueryConceptResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
