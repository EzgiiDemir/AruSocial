<?php

namespace App\Models;

use App\Services\Ai\RetrievalVersion;
use App\Support\RequestMemo;
use App\Support\TableStamp;
use App\Support\TextFold;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * A query concept: wordings → target domains, retrieval terms and preferred
 * page paths (admin → AICAD → Query concepts). Improves routing and
 * retrieval only; it never supplies an answer.
 */
class AiQueryConcept extends Model
{
    private const CACHE_KEY = 'ai_query_concepts:index';

    protected $fillable = ['concept', 'label', 'phrases', 'domains', 'retrieval_terms', 'preferred_paths', 'active', 'created_by'];

    protected function casts(): array
    {
        return [
            'phrases' => 'array',
            'domains' => 'array',
            'retrieval_terms' => 'array',
            'preferred_paths' => 'array',
            'active' => 'boolean',
        ];
    }

    /**
     * Active concepts with folded phrases. Read once per request; cached
     * under the table stamp so any write path invalidates it.
     *
     * @return list<array{concept: string, phrases: list<string>, domains: list<string>, retrieval_terms: list<string>, preferred_paths: list<string>}>
     */
    public static function index(): array
    {
        return app(RequestMemo::class)->remember(self::CACHE_KEY, function (): array {
            if (! Schema::hasTable('ai_query_concepts')) {
                return [];
            }

            return Cache::remember(self::CACHE_KEY.':'.sha1(TableStamp::of('ai_query_concepts').'|'.RetrievalVersion::counter()), now()->addMinutes(10), fn (): array => self::query()
                ->where('active', true)->orderBy('id')->get()
                ->map(fn (self $c) => [
                    'concept' => (string) $c->concept,
                    'phrases' => array_values(array_unique(array_filter(array_map(
                        fn ($p) => trim(preg_replace('/\s+/u', ' ', TextFold::fold((string) $p)) ?? ''),
                        (array) $c->phrases,
                    )))),
                    'domains' => array_values((array) $c->domains),
                    'retrieval_terms' => array_values((array) $c->retrieval_terms),
                    'preferred_paths' => array_values((array) $c->preferred_paths),
                ])->all());
        });
    }

    protected static function booted(): void
    {
        $forget = static function (): void {
            app(RequestMemo::class)->forget(self::CACHE_KEY);
            TableStamp::touched('ai_query_concepts');
        };
        static::saved($forget);
        static::deleted($forget);
    }
}
