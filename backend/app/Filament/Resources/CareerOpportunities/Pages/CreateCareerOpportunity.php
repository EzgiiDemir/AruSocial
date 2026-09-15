<?php

namespace App\Filament\Resources\CareerOpportunities\Pages;

use App\Filament\Concerns\MintsPrefixedId;
use App\Filament\Resources\CareerOpportunities\CareerOpportunityResource;
use App\Services\AuditLogger;
use Filament\Resources\Pages\CreateRecord;

class CreateCareerOpportunity extends CreateRecord
{
    use MintsPrefixedId;

    protected static string $resource = CareerOpportunityResource::class;

    protected function idPrefix(): string
    {
        return 'career';
    }

    protected function afterCreate(): void
    {
        AuditLogger::logAsCurrentUser('create', 'career', $this->record->title);
    }
}
