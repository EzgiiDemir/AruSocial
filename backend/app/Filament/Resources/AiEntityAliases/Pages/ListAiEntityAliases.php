<?php

namespace App\Filament\Resources\AiEntityAliases\Pages;

use App\Filament\Resources\AiEntityAliases\AiEntityAliasResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAiEntityAliases extends ListRecords
{
    protected static string $resource = AiEntityAliasResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
