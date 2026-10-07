<?php

namespace App\Services\Knowledge;

use App\Models\KnowledgeDocument;
use App\Support\Utf8;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Strips the site chrome that survives HTML extraction.
 *
 * `HtmlTextExtractor` removes `<nav>`, `<header>`, `<footer>` and
 * `<form>`, which is the right first cut. It is not enough here: the
 * ARUCAD sites render their menus as ordinary lists inside the content
 * area, so every page's text begins with the same eighty lines of
 * "Hakkımızda / Yönetim / Fakülteler / …".
 *
 * That was measured doing real damage. Every page's opening passage was
 * near-identical, so their embeddings were near-identical too, and
 * ranking degenerated: "kütüphane" returned an exam-transfer page while
 * the library page scored no better than anything else. It also spent
 * the prompt's grounding budget on a menu instead of on an answer.
 *
 * The fix does not depend on markup, which is what makes it survive a
 * redesign: a line that appears on a third of the pages of a site is
 * chrome, whatever tag it came from. Real sentences do not repeat across
 * dozens of pages.
 */
class BoilerplateFilter
{
    /**
     * A line must appear on at least this share of pages to be chrome.
     *
     * MEASURED against the 78-page ARUCAD corpus. Lines per threshold:
     *
     *   >= 24 pages (0.30)   10 lines
     *   >= 16 pages (0.20)   55 lines   <- current
     *   >= 12 pages (0.15)   63 lines
     *   >=  8 pages (0.10)   64 lines
     *
     * The curve is the argument. The main site's menu sits on 22-26
     * pages, so 0.30 caught only its most common half and left the rest
     * in; 0.20 catches the whole menu; and dropping further to 0.15 and
     * 0.10 adds 9 lines and then 1, which is the plateau that says there
     * is nothing else repeating. Chrome and content are cleanly
     * separated on this corpus — chrome on 16+ pages, real content on a
     * handful — and 0.20 sits in the gap.
     */
    private const SHARE = 0.2;

    /**
     * Below this many documents, do nothing.
     *
     * Three, not eight, because the language groups are uneven: this
     * corpus has 73 Turkish pages and 5 English ones, and a minimum of
     * eight sent the English group to the corpus-wide set — which is
     * built from Turkish pages and therefore contains none of the
     * English menu. The English pages kept their navigation and went on
     * out-ranking real answers, which is the exact bug the language
     * split was added to fix.
     *
     * The floor in `$threshold` below is what keeps this safe: a line is
     * never chrome on the strength of a single page, so the smallest
     * group still needs the same line on two separate pages. Two short
     * pages sharing a line by coincidence costs one line of context;
     * leaving a menu in costs the ranking.
     */
    private const MIN_DOCUMENTS = 3;

    /**
     * Long lines are prose, not menu items.
     *
     * A navigation label is a few words. Dropping a 300-character
     * paragraph because it appears on several pages would remove real
     * content — a shared admissions note, say — which is a worse failure
     * than leaving a menu in.
     */
    private const MAX_LINE_CHARS = 120;

    private const CACHE_KEY = 'knowledge:boilerplate:lines';

    /**
     * Remove the chrome lines from one document's text.
     *
     * `$language` matters. The site has a Turkish menu and an English
     * menu, and they are different text on different pages: counted
     * across the whole corpus the Turkish menu (22-26 of 78 pages)
     * cleared the threshold while the English one (a smaller section)
     * did not, so English pages kept their navigation and then
     * out-ranked real Turkish answers on English questions. Counting
     * within a language makes each menu common in its own group.
     */
    public function strip(string $content, ?string $language = null): string
    {
        $common = $this->commonLines($language);
        if ($common === []) {
            return $content;
        }

        $kept = [];
        foreach (preg_split('/\R/u', $content) ?: [] as $line) {
            $key = $this->normalise((string) $line);
            if ($key !== '' && isset($common[$key])) {
                continue;
            }
            $kept[] = $line;
        }

        // Collapse the runs of blank lines the removals leave behind, so
        // paragraph splitting downstream still sees real paragraphs.
        $text = implode("\n", $kept);

        return trim(preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text);
    }

    /**
     * Lines that appear on at least SHARE of the pages in a language.
     *
     * Falls back to the whole corpus when a language group is too small
     * to say anything — "a third of three pages" is one page, which is a
     * coincidence, not a menu.
     *
     * Cached for a few minutes: an indexing run calls this once per page
     * and the answer cannot change during the run.
     *
     * @return array<string, true>
     */
    public function commonLines(?string $language = null): array
    {
        $key = self::CACHE_KEY.':'.($language ?: 'all');

        return Cache::remember($key, now()->addMinutes(10), function () use ($language): array {
            $scope = fn () => $language === null
                ? KnowledgeDocument::query()
                : KnowledgeDocument::query()->where('language', $language);

            $total = $scope()->count();

            // Too few pages in this language to tell chrome from content.
            // Fall back to the corpus-wide set, which at least catches
            // whatever the rest of the site repeats.
            if ($language !== null && $total < self::MIN_DOCUMENTS) {
                return $this->commonLines(null);
            }

            if ($total < self::MIN_DOCUMENTS) {
                return [];
            }

            $counts = [];
            $scope()
                ->select(['id', 'content'])
                ->chunk(50, function ($documents) use (&$counts): void {
                    foreach ($documents as $document) {
                        // Per document, not per occurrence: a menu repeated
                        // twice on one page is still one page.
                        $seen = [];
                        foreach (preg_split('/\R/u', Utf8::clean((string) $document->content)) ?: [] as $line) {
                            $key = $this->normalise((string) $line);
                            if ($key === '' || isset($seen[$key])) {
                                continue;
                            }
                            $seen[$key] = true;
                            $counts[$key] = ($counts[$key] ?? 0) + 1;
                        }
                    }
                });

            $threshold = max(2, (int) ceil($total * self::SHARE));
            $common = [];
            foreach ($counts as $key => $count) {
                if ($count >= $threshold) {
                    $common[$key] = true;
                }
            }

            return $common;
        });
    }

    /** Forget the cached sets — used after a crawl changes the corpus. */
    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY.':all');
        foreach (KnowledgeDocument::query()->distinct()->pluck('language') as $language) {
            Cache::forget(self::CACHE_KEY.':'.($language ?: 'all'));
        }
    }

    /** '' for a line that cannot be chrome (too long, or empty). */
    private function normalise(string $line): string
    {
        $line = trim(preg_replace('/\s+/u', ' ', $line) ?? $line);
        if ($line === '' || mb_strlen($line) > self::MAX_LINE_CHARS) {
            return '';
        }

        return Str::lower($line);
    }
}
