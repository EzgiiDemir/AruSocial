<?php

namespace App\Filament\Resources\RoleGrants\Pages;

use App\Filament\Resources\RoleGrants\RoleGrantResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRoleGrants extends ListRecords
{
    protected static string $resource = RoleGrantResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
