<?php

namespace App\Services\Moderation;

/**
 * One piece of text reduced to the several shapes the policy rules need.
 *
 * `canonical` is the single-alphabet, de-obfuscated form every lexicon term
 * is also folded into, so comparisons happen on equal footing. `compact` is
 * the same without spaces (catches "n a h u y" style splits). `quoted` holds
 * whatever the author put inside quotation marks, because quoting a slur to
 * condemn or report it is not the same act as using it.
 */
final class NormalizedText
{
    /**
     * @param  list<string>  $tokens
     * @param  list<string>  $quoted  Segments the author put in quote marks.
     * @param  list<string>  $obfuscations  Evasion techniques detected.
     */
    public function __construct(
        public readonly string $original,
        public readonly string $canonical,
        public readonly string $compact,
        public readonly array $tokens,
        public readonly array $quoted,
        public readonly array $obfuscations,
        public readonly bool $hasMention,
        public readonly bool $suspicious,
    ) {}

    public function has(string $needle): bool
    {
        if ($needle === '') {
            return false;
        }
        if (str_contains($this->canonical, $needle)) {
            return true;
        }

        // A phrase split by separators ("n.a.h.u.y") survives here.
        return str_contains($this->compact, str_replace(' ', '', $needle));
    }

    /** True when any of the phrases appears. */
    public function hasAny(array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($this->has($needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when a token *starts with* $prefix — the cheap way to survive
     * Turkish/Russian agglutination ("aptal" → "aptalsın", "идиот" →
     * "идиотом") without the mid-word false positives a bare substring
     * gives ("mal" inside "normal").
     */
    public function hasTokenPrefix(string $prefix): bool
    {
        if ($prefix === '') {
            return false;
        }
        foreach ($this->tokens as $token) {
            if (str_starts_with($token, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** Whether $needle only ever appears inside a quoted segment. */
    public function onlyInsideQuotes(string $needle): bool
    {
        if ($this->quoted === []) {
            return false;
        }
        $insideQuotes = false;
        foreach ($this->quoted as $segment) {
            if (str_contains($segment, $needle)) {
                $insideQuotes = true;
                break;
            }
        }
        if (! $insideQuotes) {
            return false;
        }

        // Strip every quoted run and see whether the term still shows up.
        $outside = $this->canonical;
        foreach ($this->quoted as $segment) {
            $outside = str_replace($segment, ' ', $outside);
        }

        return ! str_contains($outside, $needle);
    }
}
