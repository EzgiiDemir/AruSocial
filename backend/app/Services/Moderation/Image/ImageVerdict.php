<?php

namespace App\Services\Moderation\Image;

/**
 * The outcome of visual moderation for one image.
 *
 * ERROR is a distinct outcome rather than a flavour of REVIEW because the
 * two need different operational responses: a review queue growing means
 * students are posting borderline things, an error count growing means an
 * operator has to go fix a machine. Both hold the content.
 */
final class ImageVerdict
{
    public const ALLOW = 'allow';
    public const REVIEW = 'review';
    public const BLOCK = 'block';
    public const ERROR = 'error';
    /** Not a policy violation — the upload itself was unusable. */
    public const INVALID = 'invalid';

    /**
     * @param  array<string, float>  $scores
     * @param  list<string>  $categories  Categories that crossed a threshold.
     */
    public function __construct(
        public readonly string $decision,
        public readonly array $scores = [],
        public readonly array $categories = [],
        public readonly ?string $model = null,
        public readonly ?string $modelVersion = null,
        public readonly ?string $policyVersion = null,
        public readonly ?int $latencyMs = null,
        public readonly ?string $reasonCode = null,
        public readonly ?string $reasonDetail = null,
    ) {}

    /** The single question the storage layer asks. */
    public function mayPublish(): bool
    {
        return $this->decision === self::ALLOW;
    }

    /** REVIEW is uncertainty, and uncertainty must never cost a strike. */
    public function isViolation(): bool
    {
        return $this->decision === self::BLOCK;
    }

    public function topCategory(): ?string
    {
        return $this->categories[0] ?? null;
    }
}
