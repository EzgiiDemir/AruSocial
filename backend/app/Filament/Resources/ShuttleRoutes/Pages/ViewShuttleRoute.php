<?php

namespace App\Filament\Resources\ShuttleRoutes\Pages;

use App\Filament\Resources\ShuttleRoutes\ShuttleRouteResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewShuttleRoute extends ViewRecord
{
    protected static string $resource = ShuttleRouteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
