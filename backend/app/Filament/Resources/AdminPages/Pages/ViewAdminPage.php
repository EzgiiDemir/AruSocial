<?php

namespace App\Filament\Resources\AdminPages\Pages;

use App\Filament\Resources\AdminPages\AdminPageResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewAdminPage extends ViewRecord
{
    protected static string $resource = AdminPageResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }
}
