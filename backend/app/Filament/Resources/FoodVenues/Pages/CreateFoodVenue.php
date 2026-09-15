<?php

namespace App\Filament\Resources\FoodVenues\Pages;

use App\Filament\Concerns\MintsPrefixedId;
use App\Filament\Resources\FoodVenues\FoodVenueResource;
use App\Services\AuditLogger;
use Filament\Resources\Pages\CreateRecord;

class CreateFoodVenue extends CreateRecord
{
    use MintsPrefixedId;

    protected static string $resource = FoodVenueResource::class;

    protected function idPrefix(): string
    {
        return 'food';
    }

    protected function afterCreate(): void
    {
        AuditLogger::logAsCurrentUser('create', 'food', $this->record->name);
    }
}
