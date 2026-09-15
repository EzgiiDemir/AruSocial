<?php

namespace App\Filament\Resources\FoodVenues\Pages;

use App\Filament\Resources\FoodVenues\FoodVenueResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFoodVenues extends ListRecords
{
    protected static string $resource = FoodVenueResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
