<?php

namespace App\Services\Moderation\Image;

/**
 * What a visual classifier saw — signals only, never a verdict.
 *
 * `available` is the field that carries the weight. It answers "did
 * something actually look at these pixels", and it is deliberately
 * separate from the scores: an empty score list from a working model
 * ("this looks fine") and an empty score list because the model never
 * ran are opposite facts, and code that cannot tell them apart will
 * eventually publish the second one.
 */
final class ImageSignal
{
    /**
     * @param  array<string, float>  $scores  category => confidence
     */
    private function __construct(
        public readonly bool $available,
        public readonly array $scores = [],
        public readonly ?string $model = null,
        public readonly ?string $modelVersion = null,
        public readonly ?int $latencyMs = null,
        public readonly ?string $failureCode = null,
        public readonly ?string $failureDetail = null,
    ) {}

    /** @param array<string, float> $scores */
    public static function scored(
        array $scores,
        ?string $model,
        ?string $modelVersion,
        ?int $latencyMs,
    ): self {
        return new self(
            available: true,
            scores: $scores,
            model: $model,
            modelVersion: $modelVersion,
            latencyMs: $latencyMs,
        );
    }

    /**
     * Nothing inspected the image. Every caller must treat this as a
     * reason to hold, whatever the cause — disabled, unreachable, timed
     * out, 500, unparseable body, model not loaded.
     */
    public static function unavailable(string $code, ?string $detail = null): self
    {
        return new self(
            available: false,
            failureCode: $code,
            failureDetail: $detail,
        );
    }

    /**
     * The file was refused before inference: not a decodable image, too
     * large, too many pixels. This is validation, not policy — the
     * uploader made a mistake, they have not violated anything, and it
     * must never contribute to a strike.
     */
    public static function rejected(string $code, ?string $detail = null): self
    {
        return new self(
            available: false,
            failureCode: $code,
            failureDetail: $detail,
        );
    }

    public function scoreFor(string $category): float
    {
        return (float) ($this->scores[$category] ?? 0.0);
    }

    /** Validation failures are the uploader's problem to fix, not a violation. */
    public function isValidationFailure(): bool
    {
        return in_array($this->failureCode, [
            'INVALID_IMAGE', 'FILE_TOO_LARGE', 'IMAGE_TOO_LARGE', 'EMPTY_FILE',
        ], true);
    }
}
