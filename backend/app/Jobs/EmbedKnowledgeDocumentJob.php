<?php

namespace App\Jobs;

use App\Models\KnowledgeDocument;
use App\Services\Knowledge\KnowledgeIndexer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * "Re-embed" for one crawled page: rebuilds its chunks and their vectors
 * with the current embedding model, off the request thread.
 */
class EmbedKnowledgeDocumentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $backoff = 60;

    public int $timeout = 120;

    public function __construct(public readonly string $documentId) {}

    public function handle(KnowledgeIndexer $indexer): void
    {
        $document = KnowledgeDocument::query()->find($this->documentId);
        if ($document !== null) {
            $indexer->index($document);
        }
    }
}
