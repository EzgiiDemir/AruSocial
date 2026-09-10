<?php

namespace App\Jobs;

use App\Models\MediaItem;
use App\Models\User;
use App\Services\Moderation\Image\ImageModerationRunner;
use App\Services\Moderation\Image\ImageVerdict;
use App\Services\Moderation\Workflow\ModerationNotifier;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Scans one already-stored, not-yet-published image.
 *
 * The safety property here is structural rather than clever: the item is
 * written as `pending` *before* this job is dispatched, and only this job
 * can move it to `approved`. So every way the queue can fail — worker
 * stopped, backlog, process killed mid-run, job lost entirely — leaves
 * the image exactly where it was: private and unpublished. Nothing has to
 * remember to fail closed, because there is no code path that publishes
 * without a verdict.
 */
class ModerateImageJob implements ShouldQueue
{
    use Queueable;

    /**
     * Three attempts, then the failure is recorded rather than retried
     * forever. A job that retries indefinitely against a dead classifier
     * buries the queue and hides the outage.
     */
    public int $tries = 3;

    public int $backoff = 30;

    /** Longer than the provider timeout, so the HTTP call decides, not this. */
    public int $timeout = 120;

    public function __construct(public readonly string $mediaItemId) {}

    /**
     * Idempotency: only an item still awaiting a decision is scanned.
     *
     * A retry after a partial failure, a duplicate dispatch, or an
     * at-least-once delivery must not produce a second verdict, a second
     * evidence row, or a second strike. Re-reading the current status and
     * bailing on anything terminal is what makes that true.
     */
    public function handle(ImageModerationRunner $runner): void
    {
        $item = MediaItem::find($this->mediaItemId);

        if ($item === null) {
            // Deleted between dispatch and execution. Nothing to protect.
            return;
        }

        $current = (string) ($item->moderation_status ?? '');
        if (! in_array($current, ['pending', 'moderation_error'], true)) {
            Log::info('moderation.image.job_skipped_terminal_state', [
                'media_item' => $item->id,
                'status' => $current,
            ]);

            return;
        }

        if (! $runner->enabled()) {
            // Configuration changed after dispatch. Leaving it pending is
            // the only honest outcome — approving it would publish an
            // image nothing inspected.
            Log::warning('moderation.image.job_without_provider', ['media_item' => $item->id]);

            return;
        }

        $verdict = $runner->scanStored($item);
        $runner->recordEvidence(
            userId: $item->user_id === null ? null : (string) $item->user_id,
            verdict: $verdict,
            sourceFeature: 'media.moderate_job',
            contentId: $item->id,
        );

        $item->update(['moderation_status' => $runner->statusFor($verdict)]);

        // Held and blocked content needs a case, or it is private with no
        // route to a human and no case for its author to appeal against.
        $case = $runner->openCaseIfHeld($item, $verdict);

        // In queued mode the upload already returned 201, so the verdict
        // arrives after the student has moved on. Without a notice their
        // photo simply never appears and the app looks broken.
        $owner = $item->user_id === null ? null : User::find($item->user_id);
        if ($owner !== null) {
            $notifier = app(ModerationNotifier::class);
            $contentType = str_starts_with((string) $item->mime_type, 'video/')
                ? 'video' : 'image';

            match ($verdict->decision) {
                ImageVerdict::BLOCK => $notifier->contentRemoved(
                    $owner, $contentType, $case?->id),
                ImageVerdict::REVIEW => $notifier->contentUnderReview(
                    $owner, $contentType, $case?->id),
                // ALLOW is silent: telling someone their ordinary photo
                // passed a check is noise, and it advertises that every
                // upload is inspected.
                default => null,
            };
        }

        // ERROR is retried; a decided verdict is final. Throwing here is
        // what puts the job back on the queue, so it must happen only
        // when nothing actually looked at the image.
        if ($verdict->decision === ImageVerdict::ERROR) {
            throw new \RuntimeException(
                'Image moderation unavailable: '.($verdict->reasonCode ?? 'unknown')
            );
        }
    }

    /**
     * Every retry is exhausted and the image still has no verdict.
     *
     * It stays unpublished. This exists to make the failure loud — an
     * operator has to know the classifier is down, because the alternative
     * is a queue quietly filling with content students think they posted.
     */
    public function failed(\Throwable $e): void
    {
        Log::error('moderation.image.job_failed', [
            'media_item' => $this->mediaItemId,
            'error' => $e->getMessage(),
        ]);

        $item = MediaItem::find($this->mediaItemId);
        if ($item !== null && (string) $item->moderation_status === 'pending') {
            $item->update(['moderation_status' => 'moderation_error']);
        }
    }
}
