<?php

namespace App\Filament\Resources\AiEntityAliases\Pages;

use App\Filament\Resources\AiEntityAliases\AiEntityAliasResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAiEntityAlias extends CreateRecord
{
    protected static string $resource = AiEntityAliasResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->user()?->email;

        return $data;
    }
}
