<?php

namespace App\Filament\Resources\AdminPages\Pages;

use App\Filament\Resources\AdminPages\AdminPageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAdminPages extends ListRecords
{
    protected static string $resource = AdminPageResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
