<?php

namespace App\Services\Moderation;

use App\Models\ModerationEvent;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The single gate every piece of user-generated content passes through.
 *
 * There is deliberately one entry point (`check`) rather than a moderation
 * call copy-pasted into each controller: a new content feature that forgets
 * to call this is the only way something can reach the timeline unchecked,
 * and that is far easier to spot in review than a missing `if` inside one
 * of thirty handlers.
 *
 * Order of operations, and why:
 *   1. Ban check — a banned account cannot submit anything at all.
 *   2. Local engine — instant, offline, catches deliberate obfuscation and
 *      campus-specific policy the general model is not tuned for.
 *   3. OpenAI omni-moderation — the primary semantic layer, text + images.
 *   4. Decide, record, apply the strike ladder.
 *
 * The check always runs *before* the content row is created, so rejected
 * content never exists publicly even briefly.
 */
class ContentModerator
{
    public function __construct(
        private readonly OpenAiModerationClient $provider = new OpenAiModerationClient(),
        private readonly TextPolicyEngine $localEngine = new TextPolicyEngine(),
        private readonly PenaltyLadder $ladder = new PenaltyLadder(),
    ) {}

    /**
     * Moderates one submission.
     *
     * @param  list<string>  $imageUrls  Data URIs / URLs to inspect alongside the text.
     */
    public function check(
        User $user,
        ?string $text,
        string $contentType,
        string $sourceFeature,
        array $imageUrls = [],
    ): ModerationOutcome {
        if ($this->ladder->isCurrentlyBanned($user)) {
            return ModerationOutcome::banned($user->banned_until);
        }

        $hash = $this->submissionHash($user, $text, $contentType, $imageUrls);

        // A double-tapped submit button must not cost two strikes.
        $existing = ModerationEvent::where('submission_hash', $hash)
            ->where('user_id', $user->id)
            ->where('created_at', '>=', Carbon::now()->subMinutes(10))
            ->first();
        if ($existing !== null) {
            return ModerationOutcome::fromRepeat($existing);
        }

        $local = $text !== null && trim($text) !== ''
            ? $this->localEngine->evaluate($text)
            : null;

        $provider = $this->provider->inspect($text, $imageUrls);

        // "No key configured" and "the provider is down" are different
        // situations. An install with no key runs on the local engine alone
        // — that is a deployment choice, not an outage. A configured
        // provider that cannot be reached means content genuinely went
        // uninspected, so it is held rather than published.
        $outage = ! $provider->available && $provider->unavailableReason !== 'not_configured';

        // Media has no local fallback. The offline engine reads text; it
        // cannot look at pixels, so when a submission carries images (or
        // video frames) and the provider did not actually inspect them,
        // nothing has judged that content at all. Unlike text — where
        // degrading to the local engine still enforces a real policy —
        // "no key configured" here means completely uninspected media, and
        // approving it would publish exactly what this gate exists to stop.
        // So media is held whatever the reason, including a missing key.
        if ($imageUrls !== [] && ! $provider->available) {
            $this->record($user, $contentType, $sourceFeature, ModerationEvent::ACTION_REVIEW,
                $provider, $local, $text, $hash, null);

            return ModerationOutcome::mediaUninspected();
        }

        if ($outage && ! $this->localSaysBlock($local)) {
            if (! config('services.moderation.fail_open', false)) {
                $this->record($user, $contentType, $sourceFeature, ModerationEvent::ACTION_REVIEW,
                    $provider, $local, $text, $hash, null);

                return ModerationOutcome::unavailable();
            }
            Log::warning('moderation.fail_open', ['feature' => $sourceFeature]);
        }

        $providerViolations = $provider->violatedCategories();

        // Someone describing their own crisis must be handled before
        // anything that can record a strike. The provider flags this under
        // its own self-harm categories, so without this check the ladder
        // would punish exactly the person who most needs a way to speak up.
        //
        // "self-harm/instructions" is deliberately excluded: telling *other*
        // people how to hurt themselves is harmful content, not a call for
        // help, and stays on the normal enforcement path below.
        if ($this->isCryForHelp($local, $providerViolations)) {
            $this->record($user, $contentType, $sourceFeature, ModerationEvent::ACTION_REVIEW,
                $provider, $local, $text, $hash, null);

            return ModerationOutcome::support();
        }

        $blockedByProvider = $providerViolations !== [];
        $blockedByLocal = $this->localSaysBlock($local);

        if ($blockedByProvider || $blockedByLocal) {
            $categories = $blockedByProvider ? $providerViolations : ($local?->labels ?? []);
            $penalty = $this->ladder->applyStrike($user, implode(',', $categories) ?: 'policy');

            $event = $this->record($user, $contentType, $sourceFeature, ModerationEvent::ACTION_REJECTED,
                $provider, $local, $text, $hash, $penalty);

            AuditLogger::log('system', 'moderation_block', 'user', sprintf(
                '%s (%d): %s/%s [%s]',
                $user->name, $penalty['strike'], $sourceFeature, $contentType, implode(',', $categories),
            ));

            return ModerationOutcome::rejected(
                categories: $categories,
                strike: $penalty['strike'],
                action: $penalty['action'],
                bannedUntil: $penalty['banned_until'],
                eventId: $event->id,
            );
        }

        // Borderline local verdicts are published but queued for a human.
        if ($local !== null && $local->needsReview()) {
            $this->record($user, $contentType, $sourceFeature, ModerationEvent::ACTION_REVIEW,
                $provider, $local, $text, $hash, null);

            return ModerationOutcome::allowedWithReview();
        }

        if ($local !== null && $local->isWarning()) {
            $this->record($user, $contentType, $sourceFeature, ModerationEvent::ACTION_WARNED,
                $provider, $local, $text, $hash, null);

            return ModerationOutcome::allowedWithWarning($local->labels);
        }

        return ModerationOutcome::allowed();
    }

    /** Convenience for image-only submissions (avatars, attachments). */
    public function checkImage(User $user, string $imageUrl, string $contentType, string $sourceFeature, ?string $caption = null): ModerationOutcome
    {
        return $this->check($user, $caption, $contentType, $sourceFeature, [$imageUrl]);
    }

    private function localSaysBlock(?ModerationVerdict $local): bool
    {
        return $local !== null && $local->blocksPublication();
    }

    /**
     * True when the content reads as the author describing harm to
     * themselves, rather than harming someone else.
     *
     * Requires that *every* violated provider category is a self-harm one:
     * a post that is both suicidal and threatens another student still has
     * to go down the enforcement path for the part aimed at that student.
     *
     * @param  list<string>  $providerViolations
     */
    private function isCryForHelp(?ModerationVerdict $local, array $providerViolations): bool
    {
        if ($local !== null && $local->context === 'self_harm') {
            return true;
        }

        if ($providerViolations === []) {
            return false;
        }

        $supportable = ['self-harm', 'self-harm/intent'];

        return array_diff($providerViolations, $supportable) === [];
    }

    /**
     * @param  array{strike: int, action: string, banned_until: ?Carbon}|null  $penalty
     */
    private function record(
        User $user,
        string $contentType,
        string $sourceFeature,
        string $action,
        ProviderResult $provider,
        ?ModerationVerdict $local,
        ?string $text,
        string $hash,
        ?array $penalty,
    ): ModerationEvent {
        $decidedBy = match (true) {
            ! $provider->available => 'unavailable',
            $provider->violatedCategories() !== [] && $this->localSaysBlock($local) => 'both',
            $provider->violatedCategories() !== [] => 'openai',
            default => 'local',
        };

        $retainDays = (int) config('services.moderation.retain_excerpt_days', 30);

        return ModerationEvent::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'content_type' => $contentType,
            'source_feature' => $sourceFeature,
            'action' => $action,
            'flagged' => $provider->flagged || $this->localSaysBlock($local),
            'categories' => array_values(array_unique(array_merge(
                $provider->violatedCategories(),
                $local?->labels ?? [],
            ))),
            'category_scores' => $provider->significantScores(),
            'decided_by' => $decidedBy,
            'strike_number' => $penalty['strike'] ?? null,
            'penalty' => $penalty['action'] ?? null,
            'banned_until' => $penalty['banned_until'] ?? null,
            'moderation_provider' => $provider->available ? 'openai' : 'local',
            'moderation_model' => $provider->model,
            // Only a short excerpt, and only until the appeal window closes.
            'excerpt' => $text === null ? null : mb_substr(trim($text), 0, 280),
            'excerpt_purge_after' => Carbon::now()->addDays($retainDays),
            'submission_hash' => $hash,
        ]);
    }

    /** @param list<string> $imageUrls */
    private function submissionHash(User $user, ?string $text, string $contentType, array $imageUrls): string
    {
        return hash('sha256', implode('|', [
            $user->id,
            $contentType,
            trim((string) $text),
            implode(',', array_map(fn ($u) => mb_substr($u, 0, 200), $imageUrls)),
        ]));
    }
}
