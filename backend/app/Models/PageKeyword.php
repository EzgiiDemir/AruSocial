<?php

namespace App\Models;

use App\Support\TextFold;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * What one ARUCAD page is about, authored rather than inferred.
 *
 * Retrieval already reads the page's own text, and that is exactly why this
 * exists: the text says what the page CONTAINS, and a curated keyword says
 * what the page is FOR. A news item about a scholarship ceremony contains
 * "burs" many times; the scholarships page is about it. The second fact is
 * not recoverable from the first by counting words.
 */
class PageKeyword extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $primaryKey = 'url';

    protected $fillable = ['url', 'title', 'language', 'category', 'keywords', 'summary'];

    private const CACHE_KEY = 'knowledge:page-keywords';

    /**
     * This page's keywords, folded and de-duplicated.
     *
     * @return list<string>
     */
    public function terms(): array
    {
        return self::split((string) $this->keywords);
    }

    /** @return list<string> */
    public static function split(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }

        $out = [];
        foreach (preg_split('/[,\n;]+/u', $raw) ?: [] as $term) {
            $folded = TextFold::fold(trim($term));
            // Single letters and stray punctuation are not keywords, and a
            // one-character match would fire on almost every page.
            if (mb_strlen($folded) >= 3) {
                $out[$folded] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * Every page's keywords, as url => folded terms.
     *
     * Read once per request on the retrieval path and cached for a few
     * minutes, in the same shape and for the same reason as
     * CrawlSource::keyIndex(): a stale entry costs a slightly worse ranking,
     * never a wrong answer, because keywords only reorder results that
     * scoring already found.
     *
     * @return array<string, list<string>>
     */
    public static function index(): array
    {
        return Cache::remember(self::CACHE_KEY, now()->addMinutes(10), function (): array {
            if (! Schema::hasTable('page_keywords')) {
                return [];
            }

            $out = [];
            self::query()->select(['url', 'keywords'])->chunk(500, function ($rows) use (&$out): void {
                foreach ($rows as $row) {
                    $terms = $row->terms();
                    if ($terms !== []) {
                        $out[(string) $row->url] = $terms;
                    }
                }
            });

            return $out;
        });
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    protected static function booted(): void
    {
        $forget = static fn () => self::forget();
        static::saved($forget);
        static::deleted($forget);
    }
}
