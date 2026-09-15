<?php

namespace App\Filament\Resources\RoleGrants\Pages;

use App\Filament\Resources\RoleGrants\RoleGrantResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewRoleGrant extends ViewRecord
{
    protected static string $resource = RoleGrantResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
