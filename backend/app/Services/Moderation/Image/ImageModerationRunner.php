<?php

namespace App\Services\Moderation\Image;

use App\Models\MediaItem;
use App\Models\ModerationEvent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Scans an image, records the evidence, and applies the verdict.
 *
 * One implementation shared by the synchronous upload path and the queued
 * job. They must not each carry their own copy: two code paths that are
 * supposed to reach the same verdict will eventually reach different
 * ones, and the one that drifts will be the one nobody is testing.
 */
final class ImageModerationRunner
{
    public function __construct(
        private readonly ImageModerationProvider $provider = new FastApiImageModerationProvider,
        private readonly ImageModerationPolicy $policy = new ImageModerationPolicy,
    ) {}

    public function enabled(): bool
    {
        return $this->provider->isConfigured();
    }

    /** Scan raw bytes. Never throws — a failure is a verdict of ERROR. */
    public function scanBytes(string $bytes, string $filename = 'upload'): ImageVerdict
    {
        return $this->policy->decide($this->provider->inspect($bytes, $filename));
    }

    /**
     * Scan a stored item. A file that has vanished from disk is an ERROR,
     * not an ALLOW — we cannot vouch for bytes we cannot read.
     */
    public function scanStored(MediaItem $item): ImageVerdict
    {
        $disk = Storage::disk(MediaItem::disk());

        if (! $disk->exists((string) $item->file_path)) {
            return $this->policy->decide(
                ImageSignal::unavailable('FILE_MISSING', 'Stored file could not be read.')
            );
        }

        return $this->scanBytes(
            (string) $disk->get((string) $item->file_path),
            (string) ($item->file_name ?: 'upload'),
        );
    }

    /**
     * Translate a verdict into a stored moderation status.
     *
     * Exhaustive by design. There is no `default` that resolves to
     * 'approved' — an outcome nobody enumerated must never be the one
     * that publishes.
     */
    public function statusFor(ImageVerdict $verdict): string
    {
        return match ($verdict->decision) {
            ImageVerdict::ALLOW => 'approved',
            ImageVerdict::BLOCK => 'blocked',
            ImageVerdict::REVIEW => 'review',
            ImageVerdict::INVALID => 'blocked',
            default => 'moderation_error',
        };
    }

    /**
     * Evidence for every decision, including allows.
     *
     * Recording only blocks makes false negatives invisible: nobody can
     * audit what the model let through if the score was never written
     * down. Scores and versions only — never a copy of the image.
     */
    public function recordEvidence(
        ?string $userId,
        ImageVerdict $verdict,
        string $sourceFeature = 'media.upload',
        ?string $contentId = null,
    ): void {
        try {
            ModerationEvent::create([
                'id' => (string) Str::uuid(),
                'user_id' => (string) ($userId ?? ''),
                'content_type' => 'image',
                'content_id' => $contentId,
                'source_feature' => $sourceFeature,
                'action' => match ($verdict->decision) {
                    ImageVerdict::ALLOW => ModerationEvent::ACTION_ALLOWED,
                    ImageVerdict::BLOCK => ModerationEvent::ACTION_REJECTED,
                    default => ModerationEvent::ACTION_REVIEW,
                },
                'flagged' => $verdict->categories !== [],
                'categories' => $verdict->categories,
                'category_scores' => $verdict->scores,
                'decided_by' => 'image_model',
                'moderation_provider' => 'fastapi_image',
                'moderation_model' => $verdict->model,
                'model_version' => $verdict->modelVersion,
                'policy_version' => $verdict->policyVersion,
                'latency_ms' => $verdict->latencyMs,
                'excerpt' => $verdict->reasonCode,
            ]);
        } catch (\Throwable $e) {
            // A lost audit row must not change the verdict — but it is
            // never silent, because a decision nobody recorded is a
            // decision nobody can explain later.
            Log::error('moderation.image.evidence_write_failed', [
                'error' => $e->getMessage(),
                'decision' => $verdict->decision,
            ]);
        }
    }
}
