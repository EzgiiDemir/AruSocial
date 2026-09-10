<?php

namespace App\Services\Moderation\Image;

use App\Services\Moderation\VideoModerator;
use Illuminate\Support\Facades\Log;

/**
 * Visual moderation for video, built on the image classifier.
 *
 * A video is sampled into frames and every frame goes through exactly the
 * same provider, policy and thresholds as a photograph. That reuse is the
 * point: a second set of numbers for video would drift from the ones
 * actually calibrated against real content, and "safe" would come to mean
 * two different things depending on the file extension.
 *
 * Aggregation is the worst frame, not the average. Averaging is how a
 * ninety-second clip with four seconds of explicit content passes — the
 * other eighty-six seconds outvote it. For safety the maximum is the only
 * defensible aggregate.
 *
 * Frames are sampled across the whole clip rather than taken from the
 * thumbnail, because the thumbnail is chosen by the uploader and is
 * therefore the one frame guaranteed not to be representative.
 */
final class VideoModerationRunner
{
    public function __construct(
        private readonly ImageModerationRunner $images = new ImageModerationRunner,
        private readonly VideoModerator $extractor = new VideoModerator,
        private readonly ImageModerationPolicy $policy = new ImageModerationPolicy,
    ) {}

    /**
     * Scan a stored video file.
     *
     * Never throws and never returns ALLOW on an incomplete inspection.
     * Every failure — no FFmpeg, unreadable duration, no frames extracted,
     * a frame the classifier could not judge — resolves to ERROR, which
     * the caller holds. A clip we only partly looked at has not been
     * checked.
     */
    public function scanFile(string $absolutePath): ImageVerdict
    {
        if (! $this->images->enabled()) {
            return $this->policy->decide(
                ImageSignal::unavailable('NOT_CONFIGURED',
                    'No visual classifier is configured.')
            );
        }

        $extraction = $this->extractor->extractFrames($absolutePath);

        if (($extraction['available'] ?? false) !== true) {
            // The most common reason here is simply that FFmpeg is not
            // installed. That is an operational gap, not a safe state:
            // without frames nothing has seen the video at all.
            $reason = (string) ($extraction['reason'] ?? 'extraction_failed');
            Log::warning('moderation.video.extraction_unavailable', [
                'reason' => $reason,
            ]);

            return $this->policy->decide(
                ImageSignal::unavailable('FRAMES_'.mb_strtoupper($reason),
                    'Video frames could not be extracted.')
            );
        }

        $frames = $extraction['frames'] ?? [];
        if ($frames === []) {
            return $this->policy->decide(
                ImageSignal::unavailable('FRAMES_NONE', 'No frames were produced.')
            );
        }

        $worst = null;
        foreach ($frames as $index => $frame) {
            $bytes = $this->frameBytes($frame);
            if ($bytes === null) {
                return $this->policy->decide(
                    ImageSignal::unavailable('FRAME_UNREADABLE',
                        "Frame {$index} could not be read.")
                );
            }

            $verdict = $this->images->scanBytes($bytes, "frame-{$index}.jpg");

            // One frame we could not judge means the clip is unjudged.
            // Continuing would let a scanner failure mid-clip look like a
            // clean result for the frames that did answer.
            if ($verdict->decision === ImageVerdict::ERROR) {
                return $verdict;
            }

            // A single explicit frame decides the whole clip.
            if ($verdict->decision === ImageVerdict::BLOCK) {
                return $verdict;
            }

            if ($worst === null || $this->severity($verdict) > $this->severity($worst)) {
                $worst = $verdict;
            }
        }

        return $worst ?? $this->policy->decide(
            ImageSignal::unavailable('FRAMES_NONE', 'No frame produced a verdict.')
        );
    }

    /**
     * Frames arrive either as a path or as a data URI, depending on how
     * the extractor was configured. Both are read here so the caller does
     * not have to care.
     */
    private function frameBytes(string $frame): ?string
    {
        if (str_starts_with($frame, 'data:')) {
            $comma = strpos($frame, ',');
            if ($comma === false) {
                return null;
            }
            $decoded = base64_decode(substr($frame, $comma + 1), true);

            return $decoded === false ? null : $decoded;
        }

        if (! is_file($frame)) {
            return null;
        }
        $bytes = @file_get_contents($frame);

        return $bytes === false ? null : $bytes;
    }

    private function severity(ImageVerdict $verdict): int
    {
        return match ($verdict->decision) {
            ImageVerdict::ALLOW => 0,
            ImageVerdict::REVIEW => 1,
            ImageVerdict::INVALID => 2,
            ImageVerdict::BLOCK => 3,
            default => 4,
        };
    }
}
