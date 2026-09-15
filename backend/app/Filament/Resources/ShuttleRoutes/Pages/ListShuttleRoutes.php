<?php

namespace App\Filament\Resources\ShuttleRoutes\Pages;

use App\Filament\Resources\ShuttleRoutes\ShuttleRouteResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListShuttleRoutes extends ListRecords
{
    protected static string $resource = ShuttleRouteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
