<?php

namespace App\Filament\Resources\CrawlSources\Pages;

use App\Filament\Resources\CrawlSources\CrawlSourceResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCrawlSource extends EditRecord
{
    protected static string $resource = CrawlSourceResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
