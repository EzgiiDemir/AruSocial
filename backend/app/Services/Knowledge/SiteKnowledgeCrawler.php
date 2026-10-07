<?php

namespace App\Services\Knowledge;

use App\Models\CrawlSource;

use App\Models\KnowledgeDocument;

use App\Models\KnowledgeSource;

use App\Models\PageKeyword;

use App\Services\Ai\SourceAuthority;

use App\Support\QueryLanguage;

use Illuminate\Http\Client\Response;

use Illuminate\Support\Carbon;

use Illuminate\Support\Facades\Http;

use Illuminate\Support\Facades\Log;

use Throwable;

/**

 * Self-hosted crawler for the public ARUCAD websites.

 *

 * Fetches each configured page, extracts its readable text, and stores it in

 * `knowledge_documents`. No external crawling/search service is involved — it

 * is plain HTTP plus HTML-to-text, so the university's public content lives in

 * our own database and Ask ARUVERSE can answer from it.

 *

 * Scope is deliberately narrow and safe:

 *   - only hosts in config('knowledge.allowed_domains');

 *   - never a path under config('knowledge.excluded_path_prefixes')

 *     (wp-admin, login, account, …), so no authenticated or personal area;

 *   - only text/html and application/pdf responses.

 */

class SiteKnowledgeCrawler

{

    private function progress(string $message): void
    {
        if (app()->runningInConsole() && defined('STDOUT')) {
            fwrite(STDOUT, $message.PHP_EOL);
        }
    }

    /** @var array<string, bool> URLs already handled this run. */

    private array $seen = [];

    /** @var list<string> Frontier of URLs still to fetch. */

    private array $queue = [];

    /** @var list<string>|null Cached allow-list for this run. */

    private ?array $allowedDomains = null;

    private int $fetched = 0;

    private int $updated = 0;

    private int $skipped = 0;

    private int $failed = 0;

    /**

     * Run one crawl pass.

     *

     * @param  bool  $force  Re-fetch even pages fresher than the refresh window.

     * @return array<string, int|string>

     */

    public function crawl(bool $force = false): array
    {
        $this->seen = [];
        $this->queue = [];
        $this->fetched = 0;
        $this->updated = 0;
        $this->skipped = 0;
        $this->failed = 0;

        $this->progress('Preparing crawl seeds...');

        foreach ($this->seedUrls() as $seed) {
            $this->enqueue($this->normalize((string) $seed));
        }

        $this->progress('Seeds prepared. Queue: '.count($this->queue));

        if ((bool) config('knowledge.use_sitemaps', true)) {
            $this->progress('Discovering sitemaps...');
            $this->discoverSitemaps();
            $this->progress('Sitemap discovery finished. Queue: '.count($this->queue));
        }

        $this->progress('Preparing boilerplate filter...');
        app(BoilerplateFilter::class)->forget();
        $this->progress('Boilerplate filter ready.');

        $maxPages = max(1, (int) config('knowledge.max_pages', 120));
        $discover = (bool) config('knowledge.discover_links', true);
        $delayMs = max(0, (int) config('knowledge.delay_ms', 500));
        $attempt = 0;

        while ($this->queue !== [] && $this->fetched < $maxPages) {
            $url = array_shift($this->queue);

            if (! is_string($url) || $url === '') {
                continue;
            }

            $attempt++;

            $this->progress('');
            $this->progress(sprintf(
                '[%d] fetched=%d/%d queue=%d',
                $attempt,
                $this->fetched,
                $maxPages,
                count($this->queue),
            ));
            $this->progress('URL: '.$url);

            $startedAt = microtime(true);

            try {
                $result = $this->handle($url, $force, $discover);
            } catch (Throwable $e) {
                $this->failed++;
                $result = 'failed';
                $this->trackFailure($url, 'unexpected: '.$e->getMessage());
                $this->progress('ERROR: '.$e->getMessage());
            }

            $elapsed = microtime(true) - $startedAt;

            $this->progress(sprintf(
                'Result: %s | %.2fs | updated=%d skipped=%d failed=%d',
                $result,
                $elapsed,
                $this->updated,
                $this->skipped,
                $this->failed,
            ));

            if ($result === 'fetched' && $delayMs > 0) {
                usleep($delayMs * 1000);
            }
        }

        $this->progress('');
        $this->progress('Crawl loop finished.');

        return [
            'fetched' => $this->fetched,
            'updated' => $this->updated,
            'skipped' => $this->skipped,
            'failed' => $this->failed,
            'documents' => KnowledgeDocument::count(),
        ];
    }

    /**

     * Refresh one known public ARUCAD page on demand.

     *

     * This uses the exact same domain allow-list, excluded-path checks, DNS

     * safety and response validation as the scheduled crawl. It intentionally

     * cannot be used as an arbitrary URL fetcher.

     */

    public function refreshUrl(string $url, bool $force = false): bool

    {

        $url = $this->normalize($url);

        if ($url === '' || ! $this->isAllowed($url)) {

            return false;

        }

        return in_array($this->handle($url, $force, false), ['fetched', 'skipped'], true);

    }

    private function handle(string $url, bool $force, bool $discover): string

    {

        if (! $this->isAllowed($url)) {

            return 'rejected';

        }

        // SSRF defence in depth: an allow-listed host must still resolve to a

        // public address (guards DNS rebinding to an internal service).

        if ((bool) config('knowledge.verify_public_ip', true)) {

            $host = (string) parse_url($url, PHP_URL_HOST);

            if (! UrlSafety::isPublicHost($host)) {

                Log::warning('Knowledge crawl blocked non-public host', ['url' => $url]);

                $this->failed++;

                return 'rejected';

            }

        }

        // Skip a page fetched recently, but still mine its stored links so

        // discovery keeps working across runs without re-downloading.

        if (! $force) {

            $existing = KnowledgeDocument::find(KnowledgeDocument::idForUrl($url));

            $freshFor = (int) config('knowledge.refresh_after_hours', 12);

            if ($existing && $existing->fetched_at

                && $existing->fetched_at->gt(Carbon::now()->subHours($freshFor))) {

                $this->skipped++;

                return 'skipped';

            }

        }

        try {

            $response = Http::withHeaders([

                'User-Agent' => 'ARUVERSE-KnowledgeBot/1.0 (+https://app-aruverse.arucad.edu.tr)',

                'Accept' => 'text/html,application/xhtml+xml,application/pdf',

            ])

                ->connectTimeout(5)
                ->timeout((int) config('knowledge.request_timeout', 20))

                ->withOptions([

                    'verify' => (bool) config('knowledge.verify_ssl', true),

                    // Bound redirects so a looping/abusive chain cannot walk

                    // the crawler somewhere unexpected.

                    'allow_redirects' => ['max' => max(0, (int) config('knowledge.max_redirects', 3)), 'strict' => true],

                    /*

                     * Do not buffer the body while fetching it.

                     *

                     * There was a size check further down, but it ran on

                     * `$response->body()` — after the whole file was already

                     * in memory. A single oversized upload therefore killed

                     * the run rather than being skipped: measured, one

                     * 32 MB allocation exhausted a 128 MB limit part-way

                     * through a crawl and lost the remaining pages.

                     */

                    'stream' => true,

                ])

                ->get($url);

        } catch (Throwable $e) {

            $this->failed++;

            $this->trackFailure($url, 'fetch: '.$e->getMessage());

            return 'failed';

        }

        $this->fetched++;

        // A deleted/moved page (404/410) is not a transient error: mark any

        // stored copy stale so search demotes it and the admin can see it.

        if (in_array($response->status(), [404, 410], true)) {

            $this->markDeleted($url, 'HTTP '.$response->status());

            $this->failed++;

            return 'failed';

        }

        $body = $this->boundedBody($response);

        if ($body === null) {

            $this->failed++;

            $this->trackFailure($url, 'too large: exceeds knowledge.max_bytes');

            return 'failed';

        }

        $contentType = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));

        $isHtml = in_array($contentType, ['text/html', 'application/xhtml+xml'], true);

        $isPdf = $contentType === 'application/pdf'

            || ($contentType === 'application/octet-stream' && str_ends_with(strtolower((string) parse_url($url, PHP_URL_PATH)), '.pdf'));

        $isSitemap = str_contains($contentType, 'xml')

            && str_contains(strtolower((string) parse_url($url, PHP_URL_PATH)), 'sitemap');

        if (! $response->successful() || (! $isHtml && ! $isPdf && ! $isSitemap)) {

            $this->failed++;

            $this->trackFailure($url, 'HTTP '.$response->status().' / '.($contentType ?: 'no content-type'));

            return 'failed';

        }

        // WordPress sitemap indexes contain links to child sitemaps, not

        // content pages. Recursively mine their <loc> entries inside the same

        // bounded queue; treating XML as a failed document used to spend most

        // of the page budget before reaching the regulation PDFs.

        if ($isSitemap) {

            if (preg_match_all('~<loc>\s*([^<\s]+)\s*</loc>~i', $body, $matches)) {

                foreach ($matches[1] as $location) {

                    $normalized = $this->normalize(html_entity_decode($location, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

                    $this->enqueue($normalized, $this->isPdfUrl($normalized));

                }

            }

            return 'fetched';

        }

        if ($isPdf) {

            $bytes = $body;

            if (strlen($bytes) > (int) config('knowledge.max_pdf_bytes', 15000000)) {

                $this->failed++;

                $this->storePdfFailure($url, $response->status(), 'too_large', 'PDF exceeds configured byte limit.');

                return 'failed';

            }

            $pdf = app(PdfExtractionRunner::class)->extract($bytes);

            if ($pdf['status'] !== 'indexed') {

                $this->failed++;

                $this->storePdfFailure(

                    $url,

                    $response->status(),

                    $pdf['status'],

                    (string) $pdf['error'],

                    $pdf['title'],

                    $pdf['pageCount'],

                );

                return 'failed';

            }

            $text = mb_substr($pdf['text'], 0, (int) config('knowledge.max_chars_per_pdf', 250000));

            $this->store(

                $url,

                $pdf['title'] ?: $this->pdfTitleFromUrl($url),

                $text,

                $response->status(),

                'application/pdf',

                SourceAuthority::OFFICIAL_DOC,

                $pdf['pageCount'],

                $this->lastModified($response->header('Last-Modified')),

            );

            return 'fetched';

        }

        $html = $body;

        $text = HtmlTextExtractor::extract($html);

        $max = (int) config('knowledge.max_chars_per_page', 40000);

        if (mb_strlen($text) > $max) {

            $text = mb_substr($text, 0, $max);

        }

        // A page with almost no text (a redirect stub, an image gallery) is

        // not worth storing as knowledge. A real content page clears this

        // easily; a nav-only shell does not.

        if (mb_strlen(trim($text)) >= 60) {

            $this->store(

                $url,

                HtmlTextExtractor::title($html),

                $text,

                $response->status(),

                'text/html',

                SourceAuthority::WEB,

                null,

                $this->lastModified($response->header('Last-Modified')),

            );

        } else {

            $this->skipped++;

        }

        if ($discover) {

            foreach (HtmlTextExtractor::links($html, $url) as $link) {

                $normalized = $this->normalize($link);

                $this->enqueue($normalized, $this->isPdfUrl($normalized));

            }

        }

        return 'fetched';

    }

    private function store(

        string $url,

        ?string $title,

        string $text,

        int $status,

        string $contentType,

        int $authority,

        ?int $pageCount,

        ?Carbon $lastModified,

    ): void {

        $hash = sha1($text);

        $id = KnowledgeDocument::idForUrl($url);

        $existing = KnowledgeDocument::find($id);

        // One byte-identical official document may be linked from several

        // pages. Keep the first canonical source instead of embedding the

        // same PDF repeatedly under aliases.

        $duplicate = KnowledgeDocument::query()

            ->where('content_hash', $hash)

            ->where('id', '!=', $id)

            ->where('document_status', 'indexed')

            ->first();

        if ($duplicate !== null) {

            $duplicate->update(['last_seen_at' => now(), 'fetched_at' => now()]);

            $this->skipped++;

            return;

        }

        // Unchanged text: just bump fetched_at so the freshness window resets

        // without a needless write of identical content.

        if ($existing && $existing->content_hash === $hash) {

            $existing->update([

                'fetched_at' => now(),

                'last_seen_at' => now(),

                'http_status' => $status,

                'last_modified_at' => $lastModified,

            ]);

            $this->skipped++;

            return;

        }

        $document = KnowledgeDocument::updateOrCreate(['id' => $id], [

            'url' => $url,

            'content_type' => $contentType,

            'document_status' => 'indexed',

            'authority' => $authority,

            'domain' => (string) parse_url($url, PHP_URL_HOST),

            'title' => $title,

            'content' => $text,

            'content_hash' => $hash,

            'content_length' => mb_strlen($text),

            'http_status' => $status,

            'language' => $this->languageFor($url, $title."\n".$text),

            'page_count' => $pageCount,

            'fetched_at' => now(),

            'last_modified_at' => $lastModified,

            // A successful fetch clears any prior failure/stale state.

            'fail_count' => 0,

            'last_error' => null,

            'is_stale' => false,

            'last_seen_at' => now(),

        ]);

        // Only when the text actually changed — an unchanged page returns

        // above without reaching here, so re-embedding is never paid for

        // twice. A classifier that is down returns 0 and leaves the

        // previous passages in place rather than deleting them: stale

        // vectors still answer questions, an empty index answers nothing.

        $this->progress('  Indexing document: '.(string) $document->url);
        app(KnowledgeIndexer::class)->index($document);
        $this->progress('  Indexing finished.');

        $this->updated++;

    }

    private function storePdfFailure(

        string $url,

        int $httpStatus,

        string $documentStatus,

        string $error,

        ?string $title = null,

        ?int $pageCount = null,

    ): void {

        $document = KnowledgeDocument::updateOrCreate(

            ['id' => KnowledgeDocument::idForUrl($url)],

            [

                'url' => $url,

                'content_type' => 'application/pdf',

                'document_status' => $documentStatus,

                'authority' => SourceAuthority::OFFICIAL_DOC,

                'domain' => (string) parse_url($url, PHP_URL_HOST),

                'title' => $title ?: $this->pdfTitleFromUrl($url),

                'content' => '',

                'content_hash' => null,

                'content_length' => 0,

                'http_status' => $httpStatus,

                'language' => $this->languageFor($url, (string) $title),

                'page_count' => $pageCount,

                'fetched_at' => now(),

                'last_seen_at' => now(),

                'fail_count' => 1,

                'last_error' => mb_substr($error, 0, 500),

                'is_stale' => false,

            ],

        );

        $this->progress('  Indexing document: '.(string) $document->url);
        app(KnowledgeIndexer::class)->index($document);
        $this->progress('  Indexing finished.');

    }

    /**

     * Which language a crawled page is in.

     *

     * Was `str_contains($url, '/en/') ? 'en' : 'tr'`, which has no Russian

     * case at all: every one of the 35 pages under prospective.arucad.edu.tr

     * /ru/ was stored as Turkish, including ones whose entire text is

     * Cyrillic ("ПРОЖИВАНИЕ", "Скидки на Обучение и Цены").

     *

     * That mislabelling is not cosmetic. Retrieval adds a bonus when the

     * document's language matches the question's, so a Russian question could

     * not prefer Russian pages. Worse, BoilerplateFilter counts repeated lines

     * WITHIN a language: filed under Turkish, the Russian menu appeared on 35

     * pages out of 975 and never reached the threshold, so those pages kept

     * their navigation — the exact failure the language split exists to stop.

     *

     * The URL prefix is the site's own declaration and is trusted first. Only

     * when the URL says nothing do we read the text, because a page can be

     * Russian without advertising it in its path.

     */

    /**

     * Re-derive the language of documents already stored, without re-fetching.

     *

     * Language used to be a URL substring test with no Russian case, so the

     * corpus carries rows whose label is simply wrong. Both inputs the

     * decision needs — the URL and the text — are already in the database, so

     * correcting them does not need another pass over the network: a re-crawl

     * would re-fetch 1,190 pages to recompute two-letter codes.

     *

     * Safe to run repeatedly; it writes only rows whose label actually

     * changes, and it clears the boilerplate cache afterwards because the

     * chrome sets are counted per language.

     *

     * @return array<string, int> old => new counts, keyed "tr->ru"

     */

    public function relabelLanguages(): array

    {

        $changes = [];

        KnowledgeDocument::query()

            ->select(['id', 'url', 'title', 'content', 'language'])

            ->chunkById(200, function ($documents) use (&$changes): void {

                foreach ($documents as $document) {

                    $was = (string) $document->language;

                    $now = $this->languageFor(

                        (string) $document->url,

                        $document->title."\n".$document->content,

                    );

                    if ($now === $was) {

                        continue;

                    }

                    $document->forceFill(['language' => $now])->save();

                    $key = ($was ?: '?').'->'.$now;

                    $changes[$key] = ($changes[$key] ?? 0) + 1;

                }

            });

        if ($changes !== []) {

            app(BoilerplateFilter::class)->forget();

        }

        return $changes;

    }

    public function languageFor(string $url, string $text): string

    {

        $path = (string) parse_url($url, PHP_URL_PATH);

        foreach (['ru', 'en', 'tr'] as $code) {

            if (str_contains($path, "/{$code}/")) {

                return $code;

            }

        }

        if (str_contains($url, 'lang=en')) {

            return 'en';

        }

        // Only the opening of the page: enough to tell the script and the

        // vocabulary apart, without folding a whole PDF for a two-letter code.

        return QueryLanguage::detect(mb_substr(trim($text), 0, 600)) ?? 'tr';

    }

    private function pdfTitleFromUrl(string $url): string

    {

        $name = rawurldecode((string) basename((string) parse_url($url, PHP_URL_PATH)));

        $name = preg_replace('/\\.pdf$/i', '', $name) ?? $name;

        return trim(str_replace(['-', '_'], ' ', $name)) ?: 'ARUCAD PDF';

    }

    private function lastModified(?string $value): ?Carbon

    {

        if ($value === null || trim($value) === '') {

            return null;

        }

        try {

            return Carbon::parse($value);

        } catch (Throwable) {

            return null;

        }

    }

    /** Record a transient failure against an existing document, if any. */

    private function trackFailure(string $url, string $error): void

    {

        Log::warning('Knowledge crawl fetch failed', ['url' => $url, 'error' => $error]);

        $doc = KnowledgeDocument::find(KnowledgeDocument::idForUrl($url));

        if ($doc !== null) {

            $doc->update([

                'fail_count' => (int) $doc->fail_count + 1,

                'last_error' => mb_substr($error, 0, 500),

            ]);

        }

    }

    /** A 404/410: keep the row (history) but flag it deleted/broken. */

    private function markDeleted(string $url, string $reason): void

    {

        $doc = KnowledgeDocument::find(KnowledgeDocument::idForUrl($url));

        if ($doc !== null) {

            $doc->update([

                'is_stale' => true,

                'fail_count' => (int) $doc->fail_count + 1,

                'last_error' => $reason,

            ]);

        }

    }

    /**

     * Discover pages from each seed host's sitemap.xml. Bounded, allow-list

     * filtered, and only <loc> URLs are enqueued.

     */

    private function discoverSitemaps(): void
    {
        foreach ($this->allowedDomains() as $host) {
            $sitemapUrl = "https://{$host}/sitemap.xml";
            $this->progress('Sitemap: '.$sitemapUrl);

            try {
                $response = Http::connectTimeout(5)
                    ->timeout((int) config('knowledge.request_timeout', 20))
                    ->withOptions([
                        'verify' => (bool) config('knowledge.verify_ssl', true),
                        'allow_redirects' => [
                            'max' => max(0, (int) config('knowledge.max_redirects', 3)),
                            'strict' => true,
                        ],
                        'stream' => true,
                    ])
                    ->get($sitemapUrl);

                if (! $response->successful()) {
                    $this->progress('  -> HTTP '.$response->status());
                    continue;
                }

                $sitemap = $this->boundedBody($response) ?? '';
                $found = 0;

                if (preg_match_all('~<loc>\s*([^<\s]+)\s*</loc>~i', $sitemap, $m)) {
                    foreach ($m[1] as $loc) {
                        $normalized = $this->normalize(
                            html_entity_decode($loc, ENT_QUOTES | ENT_HTML5, 'UTF-8')
                        );
                        $this->enqueue($normalized, $this->isPdfUrl($normalized));
                        $found++;
                    }
                }

                $this->progress('  -> '.$found.' sitemap URLs found');
            } catch (Throwable $e) {
                $this->progress('  -> sitemap failed: '.$e->getMessage());
                Log::warning('Knowledge sitemap fetch failed', [
                    'url' => $sitemapUrl,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function enqueue(string $url, bool $priority = false): void

    {

        if ($url === '' || isset($this->seen[$url]) || ! $this->isAllowed($url)) {

            return;

        }

        $this->seen[$url] = true;

        if ($priority) {

            array_unshift($this->queue, $url);

        } else {

            $this->queue[] = $url;

        }

    }

    private function isPdfUrl(string $url): bool

    {

        return str_ends_with(strtolower((string) parse_url($url, PHP_URL_PATH)), '.pdf');

    }

    /**

     * The domains the crawler may fetch — the admin-managed CrawlSource list

     * (enabled rows), falling back to config when the table is empty (tests,

     * or before the first source is added). Local-only sources are excluded

     * when knowledge.crawl_local is false (crawler running off-network).

     *

     * @return list<string>

     */

    public function allowedDomains(): array

    {

        if ($this->allowedDomains !== null) {

            return $this->allowedDomains;

        }

        $rows = $this->enabledSources();

        if ($rows === []) {

            return $this->allowedDomains = array_values((array) config('knowledge.allowed_domains', []));

        }

        $crawlLocal = (bool) config('knowledge.crawl_local', true);

        $domains = [];

        foreach ($rows as $row) {

            if (! $crawlLocal && ($row['access'] ?? 'global') === 'local') {

                continue;

            }

            $domains[] = strtolower((string) $row['domain']);

        }

        return $this->allowedDomains = $domains;

    }

    /**

     * Seed URLs to start from: one per enabled source domain, plus any config

     * deep-page seeds that belong to an enabled domain (so the rich existing

     * seed list is preserved). Falls back entirely to config when no source

     * rows exist.

     *

     * @return list<string>

     */

    public function seedUrls(): array

    {

        $domains = $this->allowedDomains();

        $rows = $this->enabledSources();

        if ($rows === []) {

            return array_values(array_unique(array_merge(

                (array) config('knowledge.seed_urls', []),

                $this->operatorSourceUrls(),

            )));

        }

        $seeds = [];

        foreach ($domains as $domain) {

            $seeds[] = 'https://'.$domain.'/';

        }

        foreach ((array) config('knowledge.seed_urls', []) as $url) {

            $host = strtolower((string) parse_url((string) $url, PHP_URL_HOST));

            if (in_array($host, $domains, true)) {

                $seeds[] = (string) $url;

            }

        }

        /*

         * Every page somebody wrote keywords for is a page we have said

         * matters, so it is a seed.

         *

         * Without this the two halves disagree: the rector's page had exactly

         * the right keywords authored against it and was never fetched,

         * because link-following from the homepage had not reached it inside

         * the page budget. Curating a page and then not crawling it is the

         * worst of both — the effort is spent and the answer is still wrong.

         */

        foreach (array_keys(PageKeyword::index()) as $url) {

            $host = strtolower((string) parse_url($url, PHP_URL_HOST));

            if (in_array($host, $domains, true)) {

                $seeds[] = $url;

            }

        }

        // Page URLs an operator added under AICAD → Knowledge sources.

        $seeds = array_merge($seeds, $this->operatorSourceUrls());

        return array_values(array_unique($seeds));

    }

    /**

     * Enabled operator-managed page URLs that the allow-list permits.

     *

     * Filtered through isAllowed() here as well as at save time: disabling

     * a crawl domain must stop its pages being fetched without anyone

     * having to find and disable each page row too.

     *

     * @return list<string>

     */

    private function operatorSourceUrls(): array

    {

        try {

            $urls = KnowledgeSource::query()->where('enabled', true)->pluck('url')->all();

        } catch (Throwable) {

            return [];

        }

        return array_values(array_filter(

            array_map(fn ($url) => $this->normalize((string) $url), $urls),

            fn (string $url) => $url !== '' && $this->isAllowed($url),

        ));

    }

    /**

     * Enabled admin-managed sources as plain arrays, or [] when the table is

     * absent/empty (tests, fresh install) so config fallback kicks in.

     *

     * @return list<array{domain: string, access: string}>

     */

    private function enabledSources(): array

    {

        try {

            return CrawlSource::query()->where('enabled', true)->get()

                ->map(fn (CrawlSource $s) => ['domain' => $s->domain, 'access' => $s->access])

                ->all();

        } catch (Throwable) {

            return [];

        }

    }

    /** Host must be allow-listed and the path must not be an excluded area. */

    public function isAllowed(string $url): bool

    {

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '' || ! in_array($host, $this->allowedDomains(), true)) {

            return false;

        }

        if (! str_starts_with($url, 'https://')) {

            return false;

        }

        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '/');

        foreach ((array) config('knowledge.excluded_path_prefixes', []) as $prefix) {

            if (str_starts_with($path, $prefix)) {

                return false;

            }

        }

        return true;

    }

    /**

     * The response body, or null when it is larger than we will accept.

     *

     * Read in chunks from the stream and abandoned the moment it goes over

     * the cap, so an oversized file costs one buffer rather than the whole

     * process. Content-Length is checked first when the server sends one —

     * that skips the download entirely — but it is advisory, so the running

     * total is what actually enforces the limit.

     */

    private function boundedBody(Response $response): ?string

    {

        // Never below the PDF policy limit, or a legitimate document would

        // be refused here before that policy ever sees it.

        $cap = max(

            1024,

            (int) config('knowledge.max_bytes', 16000000),

            (int) config('knowledge.max_pdf_bytes', 15000000),

        );

        $declared = (int) $response->header('Content-Length');

        if ($declared > $cap) {

            return null;

        }

        /*

         * A dying connection is a failed fetch, not a failed run.

         *

         * Streaming moved the read out of Guzzle's own error handling, so a

         * truncated response now surfaces here as a RuntimeException from

         * Stream::read(). Left unhandled that ends the crawl — which is the

         * same whole-run failure the size cap was added to prevent, arriving

         * by a different route. One page is allowed to fail; the run is not.

         */

        $stream = $response->toPsrResponse()->getBody();

        $body = '';

        try {

            while (! $stream->eof()) {

                $body .= $stream->read(131072);

                if (strlen($body) > $cap) {

                    return null;

                }

            }

        } catch (Throwable $e) {

            Log::debug('knowledge.crawl.stream_failed', ['message' => $e->getMessage()]);

            return null;

        } finally {

            $stream->close();

        }

        return $body;

    }

    /** Drop the fragment and trailing noise so one page is one row. */

    public function normalize(string $url): string

    {

        $url = trim($url);

        $hash = strpos($url, '#');

        if ($hash !== false) {

            $url = substr($url, 0, $hash);

        }

        return $url;

    }

}
