<?php

namespace App\Filament\Resources\ServiceItems\Pages;

use App\Filament\Resources\ServiceItems\ServiceItemResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewServiceItem extends ViewRecord
{
    protected static string $resource = ServiceItemResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
