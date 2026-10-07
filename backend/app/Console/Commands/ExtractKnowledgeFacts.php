<?php

namespace App\Console\Commands;

use App\Models\KnowledgeDocument;
use App\Services\Knowledge\KnowledgeFactExtractor;
use Illuminate\Console\Command;

/** Re-derive programme facts from the stored pages (no fetching). The crawler does this on every index. */
class ExtractKnowledgeFacts extends Command
{
    protected $signature = 'knowledge:extract-facts';

    protected $description = 'Extract structured programme facts (language of instruction, duration) from crawled pages';

    public function handle(KnowledgeFactExtractor $extractor): int
    {
        $written = 0;
        $pages = 0;
        KnowledgeDocument::query()->where('document_status', 'indexed')->where('url', 'like', '%/rt-program/%')
            ->chunkById(100, function ($documents) use ($extractor, &$written, &$pages): void {
                foreach ($documents as $document) {
                    $n = $extractor->extract($document);
                    $written += $n;
                    $pages += $n > 0 ? 1 : 0;
                }
            });
        $this->info("{$written} facts from {$pages} programme pages.");

        return self::SUCCESS;
    }
}
