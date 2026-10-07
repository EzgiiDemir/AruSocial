<?php

namespace App\Models;

use App\Services\Knowledge\SiteKnowledgeCrawler;
use App\Services\Knowledge\UrlSafety;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * An operator-managed page URL the crawler must visit (admin → AICAD →
 * Knowledge sources). Its crawl result is the KnowledgeDocument with the
 * same URL.
 */
class KnowledgeSource extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_OK = 'ok';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'url', 'label', 'locale', 'enabled', 'notes', 'created_by',
        'last_crawl_status', 'last_crawl_error', 'last_crawl_requested_at', 'last_crawled_at',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'last_crawl_requested_at' => 'datetime',
            'last_crawled_at' => 'datetime',
        ];
    }

    public function document(): HasOne
    {
        return $this->hasOne(KnowledgeDocument::class, 'url', 'url');
    }

    /**
     * Why a URL may not be added, or null when it may.
     *
     * The same rules the crawler applies at fetch time — HTTPS, an allowed
     * crawl domain, no excluded path, a host that resolves only to public
     * addresses — checked at save time too, so an operator learns at once
     * that a URL will never be fetched instead of finding an empty page
     * later. The crawler checks again regardless: this is a convenience,
     * not the SSRF boundary.
     */
    public static function rejectionFor(string $url): ?string
    {
        $crawler = app(SiteKnowledgeCrawler::class);
        $normalized = $crawler->normalize(trim($url));
        if ($normalized === '' || filter_var($normalized, FILTER_VALIDATE_URL) === false) {
            return __('panel.knowledge_sources.invalid_url');
        }
        if (! $crawler->isAllowed($normalized)) {
            return __('panel.knowledge_sources.not_allowed');
        }
        $host = (string) parse_url($normalized, PHP_URL_HOST);
        if ((bool) config('knowledge.verify_public_ip', true) && ! UrlSafety::isPublicHost($host)) {
            return __('panel.knowledge_sources.not_public');
        }

        return null;
    }

    protected static function booted(): void
    {
        // Stored in the crawler's canonical form, so the row and the
        // KnowledgeDocument it produces carry the same URL.
        static::saving(function (self $source): void {
            $source->url = app(SiteKnowledgeCrawler::class)->normalize(trim((string) $source->url));
        });
    }
}
