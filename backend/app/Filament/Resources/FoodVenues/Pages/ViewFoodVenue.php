<?php

namespace App\Filament\Resources\FoodVenues\Pages;

use App\Filament\Resources\FoodVenues\FoodVenueResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewFoodVenue extends ViewRecord
{
    protected static string $resource = FoodVenueResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
