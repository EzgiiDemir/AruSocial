<?php

namespace App\Filament\Resources\AiEntityAliases\Pages;

use App\Filament\Resources\AiEntityAliases\AiEntityAliasResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAiEntityAlias extends EditRecord
{
    protected static string $resource = AiEntityAliasResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
