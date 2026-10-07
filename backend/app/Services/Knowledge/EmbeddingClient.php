<?php

namespace App\Services\Knowledge;

use App\Support\Utf8;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Embeddings for retrieval, from the classifier we already run.
 *
 * The multilingual sentence model at `:8801` is loaded for text
 * moderation and sits in memory whether or not anyone asks it a question.
 * Asking it to embed a search query as well costs one HTTP call on
 * loopback and roughly 30 ms — no second model, no extra RAM, and no
 * student question leaving ARUCAD.
 *
 * Every failure here degrades instead of raising: retrieval falls back to
 * keyword matching, which is worse but works. An assistant that returns
 * nothing because a ranking signal is unavailable would be a far larger
 * outage than the one it is reacting to.
 */
class EmbeddingClient
{
    /**
     * How long a failure suppresses further attempts.
     *
     * Without this, every question during a classifier outage pays the
     * full connection timeout before falling back — turning a degraded
     * ranking into a slow assistant, which users read as broken.
     */
    private const FAILURE_COOLDOWN_SECONDS = 60;

    private const COOLDOWN_KEY = 'knowledge:embeddings:unavailable';

    public function isEnabled(): bool
    {
        return (bool) config('knowledge.embeddings.enabled', true)
            && trim((string) config('knowledge.embeddings.base_url')) !== '';
    }

    /**
     * Vectors for one batch of texts, or null when none could be produced.
     *
     * Null is deliberately different from an empty array: it means "the
     * ranking signal is unavailable", and the caller uses that to choose
     * a fallback rather than to conclude nothing matched.
     *
     * @param  list<string>  $texts
     * @param  bool  $interactive  True for a student waiting on an answer,
     *                             where a failure must be remembered so the next question does not
     *                             pay the timeout again. False for indexing, where the cooldown
     *                             would turn one bad page into a whole skipped run — which is
     *                             exactly what it did the first time this was used in anger.
     * @return list<list<float>>|null
     */
    public function embed(array $texts, bool $interactive = true): ?array
    {
        // Crawled pages are not reliably valid UTF-8, and `json_encode`
        // refuses the whole request over a single stray byte.
        $texts = array_values(array_filter(array_map(
            static fn ($t): string => trim(Utf8::clean((string) $t)),
            $texts,
        ), static fn (string $t): bool => $t !== ''));

        if ($texts === [] || ! $this->isEnabled()) {
            return null;
        }

        if ($interactive && Cache::get(self::COOLDOWN_KEY) !== null) {
            return null;
        }

        $base = rtrim((string) config('knowledge.embeddings.base_url'), '/');
        $timeout = (float) config('knowledge.embeddings.timeout', 10);

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->post($base.'/v1/embed', ['texts' => $texts]);
        } catch (\Throwable $e) {
            $this->markUnavailable('transport: '.$e->getMessage(), $interactive);

            return null;
        }

        if (! $response->successful()) {
            $this->markUnavailable('http '.$response->status(), $interactive);

            return null;
        }

        $vectors = $response->json('vectors');
        if (! is_array($vectors) || count($vectors) !== count($texts)) {
            // A partial batch cannot be matched back to its inputs, and
            // guessing the alignment would silently attach one page's
            // meaning to another.
            $this->markUnavailable('malformed response', $interactive);

            return null;
        }

        $out = [];
        foreach ($vectors as $vector) {
            if (! is_array($vector) || $vector === []) {
                $this->markUnavailable('empty vector in batch', $interactive);

                return null;
            }
            $out[] = array_map(static fn ($v): float => (float) $v, array_values($vector));
        }

        return $out;
    }

    /** One vector, or null. Convenience for the query side. */
    public function embedOne(string $text): ?array
    {
        $vectors = $this->embed([$text]);

        return $vectors[0] ?? null;
    }

    private function markUnavailable(string $reason, bool $armCooldown = true): void
    {
        // Logged at warning, not error: the system is designed to keep
        // working without this, and an error would page someone for a
        // quality degradation.
        Log::warning('knowledge.embeddings.unavailable', ['reason' => $reason]);

        // An indexing run reports its own failures and carries on to the
        // next page. Arming the cooldown there would skip every remaining
        // page for a minute — and a backfill is a loop, so it would skip
        // essentially all of them.
        if ($armCooldown) {
            Cache::put(self::COOLDOWN_KEY, $reason, self::FAILURE_COOLDOWN_SECONDS);
        }
    }
}
