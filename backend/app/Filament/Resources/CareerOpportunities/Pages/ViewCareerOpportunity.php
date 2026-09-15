<?php

namespace App\Filament\Resources\CareerOpportunities\Pages;

use App\Filament\Resources\CareerOpportunities\CareerOpportunityResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewCareerOpportunity extends ViewRecord
{
    protected static string $resource = CareerOpportunityResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
