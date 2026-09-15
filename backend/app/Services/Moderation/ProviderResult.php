<?php

namespace App\Services\Moderation;

/**
 * What the remote moderation provider said about one submission.
 *
 * `available` is deliberately separate from `flagged`: "the provider says
 * this is fine" and "we could not reach the provider" must never collapse
 * into the same value, or an outage silently becomes an open door.
 */
final class ProviderResult
{
    /**
     * @param  array<string, bool>  $categories
     * @param  array<string, float>  $scores
     */
    private function __construct(
        public readonly bool $available,
        public readonly bool $flagged,
        public readonly array $categories,
        public readonly array $scores,
        public readonly ?string $model,
        public readonly ?string $unavailableReason,
        public readonly bool $strikeRecommended = true,
        public readonly bool $degraded = false,
        public readonly string $providerName = 'openai',

        /**
         * Categories the provider found ambiguous rather than violating.
         *
         * Kept separate from `categories` on purpose: holding a post for
         * a human and refusing it are different outcomes with different
         * consequences for the author, and a single list would collapse
         * them into whichever the caller happened to assume.
         *
         * @var array<string, bool>
         */
        public readonly array $reviewCategories = [],
    ) {}

    public static function fromApi(bool $flagged, array $categories, array $scores, string $model): self
    {
        return new self(true, $flagged, $categories, $scores, $model, null);
    }

    public static function clean(): self
    {
        return new self(true, false, [], [], null, null, false);
    }

    public static function unavailable(string $reason): self
    {
        return new self(false, false, [], [], null, $reason, false);
    }

    /**
     * The self-hosted semantic text layer.
     *
     * Margins, not probabilities: the value is how much more the text
     * resembles a category exemplar than ordinary campus writing, so it
     * is signed and can legitimately be negative. Thresholds live in
     * `moderation.text.thresholds`.
     *
     * `strikeRecommended` is false. This layer is a second opinion about
     * meaning, and a semantic model should not by itself decide that a
     * person is sanctioned — the deterministic engine, which matched
     * something a human wrote into a rule, still can.
     *
     * @param  array<string, float>  $margins
     */
    public static function fromSelfHostedText(array $margins, string $model): self
    {
        $thresholds = (array) config('moderation.text.thresholds', []);
        $flagged = [];
        $review = [];

        foreach ($margins as $category => $margin) {
            $limits = $thresholds[$category] ?? null;
            if (! is_array($limits)) {
                continue;
            }

            // A `support` threshold routes to help instead of refusal.
            // The category is emitted under the provider-neutral name
            // `self-harm`, which ContentModerator::isCryForHelp already
            // recognises — so a crisis detected semantically reaches the
            // same path as one matched by the lexicon: published, with
            // counselling contacts, no strike.
            //
            // Without this the SELF margin was computed and then thrown
            // away, because SELF deliberately has no `block` threshold.
            // Two held-out crisis posts were detected and did nothing.
            if (isset($limits['support'])
                && (float) $margin >= (float) $limits['support']) {
                $flagged['self-harm'] = true;

                continue;
            }

            if (isset($limits['block']) && (float) $margin >= (float) $limits['block']) {
                $flagged[(string) $category] = true;

                continue;
            }

            // Between `review` and `block`: ambiguous, so it goes to a
            // human rather than being refused or published. Recorded on
            // its own key so `reviewCategories()` can separate the two —
            // a held post must never be reported to the author as a
            // rejection, and must never cost a strike.
            if (isset($limits['review']) && (float) $margin >= (float) $limits['review']) {
                $review[(string) $category] = true;
            }
        }

        return new self(
            available: true,
            flagged: $flagged !== [],
            categories: $flagged,
            scores: $margins,
            model: $model,
            unavailableReason: null,
            strikeRecommended: false,
            degraded: false,
            providerName: 'self_hosted_text',
            reviewCategories: $review,
        );
    }

    /**
     * Categories that warrant a human look without warranting a refusal.
     *
     * @return list<string>
     */
    public function ambiguousCategories(): array
    {
        return array_keys(array_filter($this->reviewCategories));
    }

    /** @param list<string> $categories */
    public static function fromGateway(
        string $decision,
        array $categories,
        bool $strikeRecommended,
        bool $degraded,
    ): self {
        $normalized = array_values(array_unique(array_filter(
            array_map('strval', $categories),
            static fn (string $category): bool => $category !== '',
        )));

        return new self(
            available: true,
            flagged: $decision === 'block',
            categories: array_fill_keys($normalized, $decision === 'block'),
            scores: array_fill_keys($normalized, $decision === 'block' ? 1.0 : 0.0),
            model: 'self-hosted-gateway',
            unavailableReason: null,
            strikeRecommended: $strikeRecommended,
            degraded: $degraded,
            providerName: 'self_hosted',
        );
    }

    /**
     * Categories that either the provider flagged outright, or whose score
     * crossed our configured threshold.
     *
     * @return list<string>
     */
    public function violatedCategories(): array
    {
        if ($this->providerName === 'self_hosted') {
            return $this->flagged ? array_keys(array_filter($this->categories)) : [];
        }
        // The text layer applied its own thresholds when the result was
        // built, because its scores are signed margins rather than the
        // 0..1 probabilities the OpenAI threshold table below assumes.
        if ($this->providerName === 'self_hosted_text') {
            return array_keys(array_filter($this->categories));
        }
        $thresholds = (array) config('services.moderation.thresholds', []);
        $violations = [];

        foreach ($this->categories as $category => $isFlagged) {
            // Only categories explicitly mapped into application policy may
            // enforce. A provider can add new/unsupported response keys; an
            // unknown key must not silently become a strike.
            if ($isFlagged === true && array_key_exists($category, $thresholds)) {
                $violations[] = $category;
            }
        }

        foreach ($this->scores as $category => $score) {
            if (! isset($thresholds[$category]) || in_array($category, $violations, true)) {
                continue;
            }
            if ((float) $score >= (float) $thresholds[$category]) {
                $violations[] = $category;
            }
        }

        return array_values(array_unique($violations));
    }

    /** Highest score across every category, for ordering a review queue. */
    public function topScore(): float
    {
        return $this->scores === [] ? 0.0 : (float) max($this->scores);
    }

    /** @return array<string, float> Scores rounded for storage/readability. */
    public function significantScores(float $min = 0.01): array
    {
        $out = [];
        foreach ($this->scores as $category => $score) {
            if ((float) $score >= $min) {
                $out[$category] = round((float) $score, 4);
            }
        }
        arsort($out);

        return $out;
    }
}
