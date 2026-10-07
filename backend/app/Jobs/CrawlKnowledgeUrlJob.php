<?php

namespace App\Jobs;

use App\Models\KnowledgeDocument;
use App\Models\KnowledgeSource;
use App\Services\Knowledge\SiteKnowledgeCrawler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * "Crawl now" for one URL from the admin panel (AICAD → Knowledge sources or
 * Crawled pages). Queued because a fetch, PDF extraction and embedding can
 * take longer than a web request should.
 *
 * Goes through SiteKnowledgeCrawler::refreshUrl, so the allow-list, the
 * public-IP check and the indexer all apply exactly as in the scheduled
 * crawl. When started from a source row, the outcome is written back to it
 * so a failure is visible even when no document row was written.
 */
class CrawlKnowledgeUrlJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(
        public readonly string $url,
        public readonly ?int $sourceId = null,
    ) {}

    public function handle(SiteKnowledgeCrawler $crawler): void
    {
        try {
            $ok = $crawler->refreshUrl($this->url, true);
            $document = KnowledgeDocument::query()
                ->find(KnowledgeDocument::idForUrl($crawler->normalize($this->url)));
            $error = $ok ? null : ($document?->last_error
                ?: 'Not fetched: outside the allowed crawl domains, non-public, or no usable content.');
        } catch (Throwable $e) {
            $ok = false;
            $error = $e->getMessage();
        }

        if ($this->sourceId === null) {
            return;
        }
        KnowledgeSource::query()->whereKey($this->sourceId)->update([
            'last_crawl_status' => $ok ? KnowledgeSource::STATUS_OK : KnowledgeSource::STATUS_FAILED,
            'last_crawl_error' => $error === null ? null : Str::limit($error, 480),
            'last_crawled_at' => now(),
        ]);
    }
}
