<?php

namespace App\Filament\Resources\KnowledgeSources\Pages;

use App\Filament\Resources\KnowledgeSources\KnowledgeSourceResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditKnowledgeSource extends EditRecord
{
    protected static string $resource = KnowledgeSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
