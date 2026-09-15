<?php

namespace App\Filament\Resources\CareerOpportunities\Pages;

use App\Filament\Resources\CareerOpportunities\CareerOpportunityResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCareerOpportunities extends ListRecords
{
    protected static string $resource = CareerOpportunityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
