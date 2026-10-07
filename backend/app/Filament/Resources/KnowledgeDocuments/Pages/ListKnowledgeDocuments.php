<?php

namespace App\Filament\Resources\KnowledgeDocuments\Pages;

use App\Filament\Resources\KnowledgeDocuments\KnowledgeDocumentResource;
use Filament\Resources\Pages\ListRecords;

class ListKnowledgeDocuments extends ListRecords
{
    protected static string $resource = KnowledgeDocumentResource::class;
}
