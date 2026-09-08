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
    ) {}

    public static function fromApi(bool $flagged, array $categories, array $scores, string $model): self
    {
        return new self(true, $flagged, $categories, $scores, $model, null);
    }

    public static function clean(): self
    {
        return new self(true, false, [], [], null, null);
    }

    public static function unavailable(string $reason): self
    {
        return new self(false, false, [], [], null, $reason);
    }

    /**
     * Categories that either the provider flagged outright, or whose score
     * crossed our configured threshold.
     *
     * @return list<string>
     */
    public function violatedCategories(): array
    {
        $thresholds = (array) config('services.moderation.thresholds', []);
        $violations = [];

        foreach ($this->categories as $category => $isFlagged) {
            if ($isFlagged === true) {
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
