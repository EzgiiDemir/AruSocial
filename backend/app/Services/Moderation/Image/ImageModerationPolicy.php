<?php

namespace App\Services\Moderation\Image;

/**
 * Turns classifier scores into a verdict.
 *
 * The only place in the codebase that reads image thresholds. Controllers
 * and jobs ask this class what to do; none of them compare a score to a
 * number themselves, because a threshold duplicated into three call sites
 * is three thresholds that will drift apart.
 */
final class ImageModerationPolicy
{
    /**
     * Categories are evaluated independently and the strictest wins.
     *
     * Deliberately not an average. Averaging lets one very high score be
     * diluted by several low ones — so an image the model is 96% sure is
     * explicit passes because it is confidently not gore and confidently
     * not a weapon. For safety the maximum is the only defensible
     * aggregate.
     */
    public function decide(ImageSignal $signal): ImageVerdict
    {
        $policyVersion = (string) config('moderation.image.policy_version', 'image-v1');

        if (! $signal->available) {
            // Split before anything else: a file we could not decode is
            // the uploader's problem, a model we could not reach is ours.
            // Conflating them either punishes people for our outage or
            // hides our outage inside a validation counter.
            return new ImageVerdict(
                decision: $signal->isValidationFailure() ? ImageVerdict::INVALID : ImageVerdict::ERROR,
                policyVersion: $policyVersion,
                reasonCode: $signal->failureCode,
                reasonDetail: $signal->failureDetail,
            );
        }

        /** @var array<string, array{review: float, block: float}> $thresholds */
        $thresholds = (array) config('moderation.image.thresholds', []);

        $decision = ImageVerdict::ALLOW;
        $blocked = [];
        $review = [];

        foreach ($thresholds as $category => $limits) {
            $score = $signal->scoreFor((string) $category);
            $blockAt = (float) ($limits['block'] ?? 1.0);
            $reviewAt = (float) ($limits['review'] ?? 1.0);

            if ($score >= $blockAt) {
                $blocked[] = (string) $category;

                continue;
            }
            if ($score >= $reviewAt) {
                $review[] = (string) $category;
            }
        }

        if ($blocked !== []) {
            $decision = ImageVerdict::BLOCK;
        } elseif ($review !== []) {
            $decision = ImageVerdict::REVIEW;
        }

        return new ImageVerdict(
            decision: $decision,
            scores: $signal->scores,
            categories: $blocked !== [] ? $blocked : $review,
            model: $signal->model,
            modelVersion: $signal->modelVersion,
            policyVersion: $policyVersion,
            latencyMs: $signal->latencyMs,
        );
    }
}
