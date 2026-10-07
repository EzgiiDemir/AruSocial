<?php

namespace App\Services\Moderation;

use App\Models\ModerationEvent;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Moderation\Workflow\AccountEnforcementPolicy;
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
        private readonly ModerationClient $provider = new ModerationClient,
        private readonly TextPolicyEngine $localEngine = new TextPolicyEngine,
        private readonly AccountStanding $standing = new AccountStanding,
        private readonly AccountEnforcementPolicy $enforcement = new AccountEnforcementPolicy,
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
        array $files = [],
    ): ModerationOutcome {
        if ($this->standing->isCurrentlyBanned($user)) {
            return ModerationOutcome::banned($user->banned_until);
        }

        // A posting restriction is the lighter penalty on the same ladder:
        // the account keeps working, it just may not add content. It is
        // enforced here rather than in middleware because this is the one
        // gate every submission passes through, and because restricting
        // reading as well would turn it into a suspension.
        if ($this->standing->isPostingRestricted($user)) {
            return ModerationOutcome::postingRestricted($user->posting_restricted_until);
        }

        // Staff content is not scanned — but the ban check above runs
        // first, deliberately. A suspended account stays suspended
        // whatever its role, or "promote them to trainer" becomes a way
        // to lift a suspension.
        //
        // The exemption skips scanning, not accountability: an evidence
        // row is still written, so who published a given announcement
        // remains answerable afterwards.
        if (! ModerationExemption::appliesTo($user, $sourceFeature)) {
            $this->recordExemption($user, $contentType, $sourceFeature);

            return ModerationOutcome::allowed();
        }

        $hash = $this->submissionHash($user, $text, $contentType, $imageUrls, $files);

        // A double-tapped submit button must not cost two strikes.
        //
        // The cache exists to avoid punishing a resubmission twice, so it
        // replays a decision. It must not manufacture permission: an earlier
        // event recorded because nothing had inspected the media is not
        // evidence that the media is fine, and replaying it as "reviewed"
        // let the same photo through on a second attempt. Media therefore
        // only short-circuits on an outcome that actually blocks; anything
        // else is re-checked, which is also the only way a retry can ever
        // succeed once the provider comes back.
        $existing = ModerationEvent::where('submission_hash', $hash)
            ->where('user_id', $user->id)
            ->where('created_at', '>=', Carbon::now()->subMinutes(10))
            ->first();
        if ($existing !== null) {
            $replay = ModerationOutcome::fromRepeat($existing);
            if (($imageUrls === [] && $files === []) || ! $replay->isPublishable()) {
                return $replay;
            }
        }

        $local = $text !== null && trim($text) !== ''
            ? $this->localEngine->evaluate($text)
            : null;

        $provider = $this->provider->inspect($text, $imageUrls, $files, $sourceFeature);

        // "The provider is down" and "this install has no working provider"
        // are different situations, and only the first is an outage.
        //
        // An outage is temporary, so holding content until it clears is
        // reasonable. A rejected credential is not temporary: a revoked or
        // unpaid key is rejected identically on every retry, so treating it
        // as an outage means the queue never drains and the app simply
        // stops accepting posts — which is exactly what happened here, and
        // why the OpenAI layer had been switched off by hand to get the app
        // working again. Both "no key" and "key refused" therefore degrade
        // to the local engine; the distinct reason strings are kept because
        // they need very different fixes, and both are logged as errors.
        $degraded = ['not_configured', 'credentials_rejected'];
        $outage = ! $provider->available
            && ! in_array($provider->unavailableReason, $degraded, true);

        // Media is held when a provider that *should* have looked at it
        // could not be reached: that content genuinely went uninspected.
        //
        // An install with no provider configured is a different case, and
        // holding everything there was wrong — it put every ordinary photo
        // in the review queue forever, which is indistinguishable from the
        // upload being broken. That deployment runs on the layers it does
        // have: MediaController's format/size/pixel checks and, when one is
        // installed, the local semantic classifier — the same
        // "not configured → decide locally, unavailable → hold" rule
        // MediaController already applies to that classifier.
        if (($imageUrls !== [] || $files !== []) && $outage) {
            $this->record($user, $contentType, $sourceFeature, ModerationEvent::ACTION_REVIEW,
                $provider, $local, $text, $hash, null);

            return ModerationOutcome::mediaUninspected();
        }

        if ($outage && ! $this->localSaysBlock($local)) {
            $this->record($user, $contentType, $sourceFeature, ModerationEvent::ACTION_REVIEW,
                $provider, $local, $text, $hash, null);

            return ModerationOutcome::unavailable();
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
            // Stamped `self_harm` rather than letting `decided_by` fall to
            // 'unavailable' when the provider happens to be down.
            //
            // Without it the replay below cannot tell a support decision
            // from "nothing inspected this", so a student who posted once
            // and tried again within the idempotency window got a
            // MODERATION_UNAVAILABLE server error instead of the
            // counselling contact — measured, not hypothetical. Retrying
            // is exactly what someone in distress does.
            $this->record($user, $contentType, $sourceFeature, ModerationEvent::ACTION_REVIEW,
                $provider, $local, $text, $hash, null, 'self_harm');

            return ModerationOutcome::support();
        }

        $blockedByProvider = $providerViolations !== [];
        $blockedByLocal = $this->localSaysBlock($local);

        if ($blockedByProvider || $blockedByLocal) {
            $categories = $blockedByProvider ? $providerViolations : ($local?->labels ?? []);

            // One ladder for every path. The categories decide the
            // severity, the severity decides the points, and the points
            // decide the consequence — see AccountEnforcementPolicy. The
            // submission hash is the idempotency key, so a retry cannot be
            // charged twice even when it arrives after the replay window
            // above has closed.
            $penalty = ($blockedByLocal || $provider->strikeRecommended)
                ? $this->enforcement->recordAutomatedViolation(
                    $user, $categories !== [] ? $categories : ['policy'], 'content:'.$hash,
                )
                : null;

            $event = $this->record($user, $contentType, $sourceFeature, ModerationEvent::ACTION_REJECTED,
                $provider, $local, $text, $hash, $penalty);

            AuditLogger::log('system', 'moderation_block', 'user', sprintf(
                '%s (%s puan): %s/%s [%s]',
                $user->name, $penalty['points'] ?? 'ücretsiz', $sourceFeature, $contentType, implode(',', $categories),
            ));

            return ModerationOutcome::rejected(
                categories: $categories,
                points: $penalty['points'] ?? null,
                action: $penalty['action'] ?? null,
                until: $this->lockUntil($user, $penalty),
                eventId: $event->id,
            );
        }

        // The semantic layer found this ambiguous: above the review line,
        // below the block line. Held for a moderator rather than refused
        // or published.
        //
        // Measured, this band is worth nine more catches across the
        // labelled sets for four held safe posts out of fifty — the best
        // remaining trade, and only defensible because there is now a
        // screen that drains the queue. No strike is recorded: the system
        // is saying it does not know, and a person should not be punished
        // for the model's uncertainty.
        $ambiguous = $provider->ambiguousCategories();
        if ($ambiguous !== []) {
            $event = $this->record($user, $contentType, $sourceFeature,
                ModerationEvent::ACTION_REVIEW, $provider, $local, $text, $hash, null);

            return ModerationOutcome::heldForReview($ambiguous, $event->id);
        }

        // REVIEW is a weak/ambiguous lexical signal, not a violation. If the
        // semantic provider checked the submission and found it clean, that
        // second opinion resolves the ambiguity in favour of publication.
        // A deliberately local-only installation also stays usable: it may
        // warn/audit an uncertain match, but it must not turn every ordinary
        // mention of a sensitive subject into a hidden post.
        if ($local !== null && $local->needsReview()) {
            if ($provider->available) {
                return ModerationOutcome::allowed();
            }

            $this->record($user, $contentType, $sourceFeature, ModerationEvent::ACTION_WARNED,
                $provider, $local, $text, $hash, null);

            return ModerationOutcome::allowedWithWarning($local->labels);
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
        if ($local === null) {
            return false;
        }

        // Labels describe the topic; the verdict describes confidence and
        // context. Blocking on a label alone turned WARN/REVIEW decisions
        // (casual profanity, political discussion, ambiguous wording) back
        // into removals and was the main source of false positives.
        return $local->blocksPublication();
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
            return $providerViolations === []
                || array_diff($providerViolations, ['self-harm', 'self-harm/intent']) === [];
        }

        if ($providerViolations === []) {
            return false;
        }

        // The lexicon refusing this for some *other* reason ends the
        // question. The support path publishes, so a post that reaches it
        // by scoring just over the self-harm line goes up untouched —
        // which is how "numaranı ver güzelim, geceleri seni yalnız
        // bırakmam" came to publish: the engine had already refused it as
        // sexual harassment, and the model happened to score it SELF
        // 0.0924 against a 0.09 support line.
        //
        // A crisis post containing swearing is still a crisis post, so
        // this only applies where the engine blocks, not where it warns.
        if ($local !== null && $this->localSaysBlock($local) && $local->context !== 'self_harm') {
            return false;
        }

        $supportable = ['self-harm', 'self-harm/intent'];

        return array_diff($providerViolations, $supportable) === [];
    }

    /**
     * When the lock this decision produced ends, whichever lock it is.
     *
     * A posting restriction and a suspension are stored in different
     * columns on purpose, and the author needs to be told the right date
     * either way.
     *
     * @param  array{action: string, hours: int, points: int}|null  $penalty
     */
    private function lockUntil(User $user, ?array $penalty): ?Carbon
    {
        return match ($penalty['action'] ?? null) {
            'posting_restriction' => $user->posting_restricted_until,
            'temporary_suspension' => $user->banned_until,
            default => null,
        };
    }

    /**
     * Evidence that a submission was exempt, and on whose authority.
     *
     * Without this an exempt publication leaves no trace at all, and the
     * question "who put this on the timeline" has no answer — which is
     * exactly the question asked after something goes wrong.
     */
    private function recordExemption(User $user, string $contentType, string $sourceFeature): void
    {
        try {
            ModerationEvent::create([
                'id' => (string) Str::uuid(),
                'user_id' => (string) $user->id,
                'content_type' => $contentType,
                'source_feature' => $sourceFeature,
                'action' => ModerationEvent::ACTION_ALLOWED,
                'flagged' => false,
                'decided_by' => 'exempt',
                'moderation_provider' => 'exempt',
                'excerpt' => 'staff_exempt:'.ModerationExemption::reasonFor($user),
            ]);
        } catch (\Throwable $e) {
            Log::error('moderation.exemption_record_failed', [
                'user' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

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
        ?string $decidedByOverride = null,
    ): ModerationEvent {
        $decidedBy = $decidedByOverride ?? match (true) {
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
            'points' => $penalty['charged'] ?? null,
            'penalty' => $penalty['action'] ?? null,
            'banned_until' => $this->lockUntil($user, $penalty),
            'moderation_provider' => $provider->available ? $provider->providerName : 'local',
            'moderation_model' => $provider->model,
            // Only a short excerpt, and only until the appeal window closes.
            'excerpt' => $text === null ? null : mb_substr(trim($text), 0, 280),
            'excerpt_purge_after' => Carbon::now()->addDays($retainDays),
            'submission_hash' => $hash,
        ]);
    }

    /**
     * @param  list<string>  $imageUrls
     * @param  list<array{path:string,name?:string}>  $files
     */
    private function submissionHash(User $user, ?string $text, string $contentType, array $imageUrls, array $files): string
    {
        $fileHashes = array_map(
            static fn (array $file): string => is_file($file['path'] ?? '')
                ? (hash_file('sha256', $file['path']) ?: '')
                : '',
            $files,
        );

        return hash('sha256', implode('|', [
            $user->id,
            $contentType,
            trim((string) $text),
            implode(',', array_map(fn ($u) => mb_substr($u, 0, 200), $imageUrls)),
            implode(',', $fileHashes),
        ]));
    }
}
