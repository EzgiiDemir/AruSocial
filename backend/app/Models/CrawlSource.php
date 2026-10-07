<?php

namespace App\Models;

use App\Support\TextFold;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * One ARUCAD web site the AICAD knowledge crawler may read. Managed from the
 * admin panel. `access` is 'global' (reachable anywhere) or 'local' (only from
 * inside the ARUCAD network).
 */
class CrawlSource extends Model
{
    public const ACCESS_GLOBAL = 'global';

    public const ACCESS_LOCAL = 'local';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $primaryKey = 'domain';

    protected $fillable = ['domain', 'label', 'keys', 'access', 'enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    public function isLocalOnly(): bool
    {
        return $this->access === self::ACCESS_LOCAL;
    }

    /**
     * The routing keywords for this source, folded and de-duplicated.
     *
     * Stored as one comma-separated string because it is a handful of words
     * per row edited by hand; folded here rather than at every call site so
     * "Ücret" typed in the admin panel matches "ucret" typed by a student.
     *
     * @return list<string>
     */
    public function keyTerms(): array
    {
        $raw = trim((string) $this->keys);
        if ($raw === '') {
            return [];
        }

        $terms = [];
        foreach (preg_split('/[,\n;]+/u', $raw) ?: [] as $term) {
            $folded = TextFold::fold(trim($term));
            if ($folded !== '') {
                $terms[$folded] = true;
            }
        }

        return array_keys($terms);
    }

    /**
     * Every enabled source's keys, as domain => terms.
     *
     * Cached for a few minutes: this is read on the retrieval path and the
     * table changes when an operator edits it, which is rare. A stale entry
     * for a minute costs a slightly worse ranking, never a wrong answer —
     * the keys only reorder results that the normal scoring already found.
     *
     * @return array<string, list<string>>
     */
    public static function keyIndex(): array
    {
        return Cache::remember('crawl_sources:keys', now()->addMinutes(5), function (): array {
            if (! Schema::hasTable('crawl_sources')) {
                return [];
            }

            $out = [];
            foreach (self::query()->where('enabled', true)->get() as $source) {
                $terms = $source->keyTerms();
                if ($terms !== []) {
                    $out[TextFold::fold((string) $source->domain)] = $terms;
                }
            }

            return $out;
        });
    }

    protected static function booted(): void
    {
        // An operator editing keys expects the effect now, not in five
        // minutes.
        $forget = static fn () => Cache::forget('crawl_sources:keys');
        static::saved($forget);
        static::deleted($forget);
    }
}
