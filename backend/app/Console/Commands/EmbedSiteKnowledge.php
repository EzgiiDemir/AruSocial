<?php

namespace App\Console\Commands;

use App\Models\KnowledgeChunk;
use App\Models\KnowledgeDocument;
use App\Services\Knowledge\EmbeddingClient;
use App\Services\Knowledge\KnowledgeIndexer;
use Illuminate\Console\Command;

/**
 * Build the semantic index for pages that have already been crawled.
 *
 * The crawler indexes each page as it changes, so this exists for the two
 * cases that leaves: the first run after the feature is deployed against
 * a corpus that is already there, and a re-index after the classifier was
 * down during a crawl (when pages are stored but not embedded, on
 * purpose, so that search keeps working on whatever was indexed before).
 */
class EmbedSiteKnowledge extends Command
{
    protected $signature = 'knowledge:embed
        {--all : Re-embed every page, not only the ones with no passages}
        {--limit=0 : Stop after this many pages (0 = no limit)}
        {--text-only : Only refresh each page stored clean text; no embedding}';

    protected $description = 'Embed crawled pages so Ask ARUVERSE can search them by meaning';

    public function handle(KnowledgeIndexer $indexer, EmbeddingClient $embeddings): int
    {
        // Before the classifier check: refreshing the stored text is pure
        // string work on rows we already have, and it is the part that has
        // to be runnable when the classifier is down.
        if ($this->option('text-only')) {
            return $this->refreshCleanText($indexer);
        }

        if (! $embeddings->isEnabled()) {
            $this->error('Embeddings are disabled (knowledge.embeddings.enabled).');

            return self::FAILURE;
        }

        // Fail before doing anything rather than half-way through: a run
        // that silently indexes nothing looks identical to a run with
        // nothing to do.
        if ($embeddings->embedOne('ARUCAD') === null) {
            $this->error('The classifier did not answer. Start it on '
                .config('knowledge.embeddings.base_url').' and try again.');

            return self::FAILURE;
        }

        $query = KnowledgeDocument::query()->orderBy('id');

        if (! $this->option('all')) {
            $indexed = KnowledgeChunk::query()
                ->distinct()
                ->pluck('knowledge_document_id')
                ->all();
            if ($indexed !== []) {
                $query->whereNotIn('id', $indexed);
            }
        }

        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $query->limit($limit);
        }

        $documents = $query->get();
        if ($documents->isEmpty()) {
            $this->info('Nothing to embed. Run `php artisan knowledge:crawl` first, '
                .'or pass --all to rebuild.');

            return self::SUCCESS;
        }

        $pages = 0;
        $chunks = 0;
        $failed = 0;

        foreach ($documents as $document) {
            $written = $indexer->index($document);
            if ($written === 0) {
                $failed++;
                $this->warn('  skipped: '.$indexer->describe($document));

                continue;
            }
            $pages++;
            $chunks += $written;
        }

        $this->info("Embedded {$chunks} passages across {$pages} pages.");
        if ($failed > 0) {
            $this->warn("{$failed} page(s) produced no passages — empty content, "
                .'or the classifier stopped answering part-way through.');
        }

        return $failed > 0 && $pages === 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Re-derive the menu-stripped text for every page, without embedding.
     *
     * The strip depends on what repeats across the corpus, so it changes when
     * pages are added or their language is corrected — and retrieval reads
     * the stored copy. This is how that copy is brought up to date after a
     * crawl or a relabel without paying for a full re-embed.
     */
    private function refreshCleanText(KnowledgeIndexer $indexer): int
    {
        $updated = 0;
        $seen = 0;

        KnowledgeDocument::query()
            ->where('document_status', 'indexed')
            ->chunkById(100, function ($documents) use ($indexer, &$updated, &$seen): void {
                foreach ($documents as $document) {
                    $seen++;
                    // chunkTexts() is what computes and stores it; the
                    // passages it returns are not wanted here.
                    $before = (string) $document->content_clean;
                    // chunkTexts() writes the column and updates the model it
                    // was handed, so the new value is already here — asking
                    // the database again would be 1,181 extra queries.
                    $indexer->chunkTexts($document);
                    if ((string) $document->content_clean !== $before) {
                        $updated++;
                    }
                }
            });

        $this->info(sprintf('Refreshed clean text: %d changed of %d pages.', $updated, $seen));

        return self::SUCCESS;
    }
}
