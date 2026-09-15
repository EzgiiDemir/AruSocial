<?php

namespace App\Filament\Trainer\Resources\Events\Pages;

use App\Filament\Trainer\Resources\Events\EventResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEvents extends ListRecords
{
    protected static string $resource = EventResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
