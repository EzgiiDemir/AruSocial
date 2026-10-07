<?php

namespace App\Services\Ai;

use App\Models\AiQueryConcept;
use App\Support\PhraseMatcher;
use App\Support\RequestMemo;
use App\Support\TextFold;

/**
 * Which query concepts a question expresses ("dersler ne zaman başlıyor" →
 * academic_calendar). A concept matches only on one of its own phrases,
 * whole-phrase with a word ending allowed — so it widens retrieval for the
 * questions that name the concept and leaves every other query alone.
 */
final class QueryConcepts
{
    /**
     * @return list<array{concept: string, phrase: string, domains: list<string>, retrieval_terms: list<string>, preferred_paths: list<string>}>
     */
    public function match(string $query): array
    {
        $text = TextFold::fold($query);

        return app(RequestMemo::class)->remember('concepts:'.sha1($text), function () use ($text): array {
            $out = [];
            foreach (AiQueryConcept::index() as $concept) {
                foreach ($concept['phrases'] as $phrase) {
                    if (PhraseMatcher::position($text, $phrase, 4) !== null) {
                        unset($concept['phrases']);
                        $out[] = $concept + ['phrase' => $phrase];
                        break;
                    }
                }
            }

            return $out;
        });
    }
}
