<?php

namespace App\Filament\Resources\CrawlSources\Pages;

use App\Filament\Resources\CrawlSources\CrawlSourceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCrawlSource extends CreateRecord
{
    protected static string $resource = CrawlSourceResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Normalise: accept a pasted URL or "HTTPS://Domain/" and store the
        // bare lowercase host.
        $domain = strtolower(trim((string) ($data['domain'] ?? '')));
        $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
        $data['domain'] = rtrim(explode('/', $domain)[0], '.');

        return $data;
    }
}
