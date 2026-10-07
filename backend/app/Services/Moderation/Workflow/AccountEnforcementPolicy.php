<?php

namespace App\Services\Moderation\Workflow;

use App\Models\User;
use App\Models\UserViolation;
use App\Services\Moderation\AccountEnforcement;
use App\Services\Moderation\ViolationSeverity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * What a confirmed violation costs an account. The only thing in the
 * application that decides that.
 *
 * Every path arrives here — the content gate (text, feed, chat, stories,
 * reviews, profiles), the media classifier, and a moderator confirming a
 * report. Before, those ran three different rules on the same account:
 * warning-then-timed-ban on one, three-strikes-and-permanent-ban on
 * another, and points on the third. The same third offence meant a
 * warning or a permanent ban depending on which door it came through.
 *
 * Three rules the old single ladder got wrong, all kept:
 *
 *  - Severity matters. Spam and a credible threat cannot share a
 *    punishment path just because both count as "one violation".
 *  - REVIEW costs nothing. It means the system was unsure, and charging
 *    someone for our uncertainty is how you lose the users you built
 *    this for.
 *  - Points expire. A ladder with no decay eventually bans everyone who
 *    stays long enough.
 *
 * Bands, ladder and the category map live in `config/moderation.php`.
 */
final class AccountEnforcementPolicy
{
    /**
     * Record a violation the classifier decided on its own.
     *
     * The severity comes from the detected categories via the one shared
     * map, so an automatic decision and a moderator confirming a report of
     * the same behaviour cost the same. `decided_by` stays null, which is
     * what marks the row as machine-decided for anyone reading it later —
     * and what an appeal needs in order to be answered by a person.
     *
     * @param  list<string>  $categories
     * @param  string  $idempotencyKey  Stable for one submission, so a
     *                                  retried job or a double-tapped submit button cannot charge twice.
     * @return array{violation: UserViolation, action: string, hours: int, charged: int, points: int, duplicate: bool}
     */
    public function recordAutomatedViolation(User $user, array $categories, string $idempotencyKey): array
    {
        return $this->recordConfirmedViolation(
            user: $user,
            category: ViolationSeverity::primaryCategory($categories),
            severity: ViolationSeverity::highest($categories),
            idempotencyKey: $idempotencyKey,
        );
    }

    /**
     * Record a violation and apply the resulting consequence.
     *
     * `idempotencyKey` is what makes a retried job or a double-clicked
     * moderator button safe: the same act cannot be punished twice, and
     * a repeat call returns the original decision unchanged.
     *
     * `charged` is what this violation cost; `points` is the account's
     * total after it. Both matter: one explains this decision, the other
     * explains the consequence.
     *
     * @return array{violation: UserViolation, action: string, hours: int, charged: int, points: int, duplicate: bool}
     */
    public function recordConfirmedViolation(
        User $user,
        string $category,
        string $severity,
        string $idempotencyKey,
        ?string $caseId = null,
        ?int $decidedBy = null,
    ): array {
        $band = $this->band($severity);

        $existing = UserViolation::where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return [
                'violation' => $existing,
                'action' => (string) $existing->action_taken,
                'hours' => 0,
                'charged' => (int) $existing->points,
                'points' => $this->activePoints($user),
                'duplicate' => true,
            ];
        }

        // With enforcement off the row is still written — it is the case
        // record moderators work from, and suppressing it would break the
        // queue rather than the punishment — but it is written
        // UNCONFIRMED, so it scores no points.
        //
        // That is what keeps the documented promise that turning
        // enforcement back on does not retroactively punish anything
        // skipped while it was off. Counting the points and merely
        // skipping the lock would have banked them: the first violation
        // after the switch flipped would have landed on top of a testing
        // window's worth of accumulated points.
        $enforcing = AccountEnforcement::enabled();

        $violation = UserViolation::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'moderation_case_id' => $caseId,
            'category' => $category,
            'severity' => $severity,
            'confirmed' => $enforcing,
            'points' => $band['points'],
            'decided_by' => $decidedBy,
            'idempotency_key' => $idempotencyKey,
            'expires_at' => now()->addDays($band['expires_days']),
        ]);

        if (! $enforcing) {
            $would = $this->consequenceFor($this->activePoints($user) + $band['points']);
            AccountEnforcement::skip($user, sprintf(
                '%s (%s, +%d puan) -> %s', $category, $severity, $band['points'], $would['action'],
            ));
            $violation->update(['action_taken' => 'suppressed']);

            // The case trail records the suppression too. A testing window
            // that leaves a gap is indistinguishable afterwards from an
            // enforcement bug.
            ModerationAudit::record(
                actorType: $decidedBy === null ? ModerationAudit::ACTOR_SYSTEM : ModerationAudit::ACTOR_MODERATOR,
                actorId: $decidedBy,
                action: 'violation_suppressed',
                targetType: 'user',
                targetId: (string) $user->id,
                caseId: $caseId,
                reason: $category,
                newState: 'suppressed',
                context: ['severity' => $severity, 'would_have' => $would['action']],
            );

            return [
                'violation' => $violation,
                'action' => 'none',
                'hours' => 0,
                'charged' => 0,
                'points' => $this->activePoints($user),
                'duplicate' => false,
            ];
        }

        // Lifetime violation counter. Informational only — every
        // consequence above is decided from active points, never from
        // this column. It stays because the admin tools and existing rows
        // are built on it.
        $user->increment('strikes');
        $user->refresh();

        $points = $this->activePoints($user);
        $consequence = $this->consequenceFor($points);

        $violation->update(['action_taken' => $consequence['action']]);
        $this->apply($user, $consequence, $category);

        ModerationAudit::record(
            actorType: $decidedBy === null ? ModerationAudit::ACTOR_SYSTEM : ModerationAudit::ACTOR_MODERATOR,
            actorId: $decidedBy,
            action: 'violation_confirmed',
            targetType: 'user',
            targetId: (string) $user->id,
            caseId: $caseId,
            reason: $category,
            newState: $consequence['action'],
            context: ['severity' => $severity, 'points' => $points],
        );

        return [
            'violation' => $violation,
            'action' => $consequence['action'],
            'hours' => $consequence['hours'],
            'charged' => $band['points'],
            'points' => $points,
            'duplicate' => false,
        ];
    }

    /**
     * Withdraw a violation, because the decision was wrong.
     *
     * The row is kept and marked unconfirmed rather than deleted: "this
     * was reversed" and "this never happened" are different facts, and
     * only the first can be audited. The account's standing is then
     * recomputed from what is left, which is the only way a reversal can
     * actually give back the time it cost.
     *
     * @return bool True when something was withdrawn.
     */
    public function withdraw(User $user, string $idempotencyKey): bool
    {
        $violation = UserViolation::where('idempotency_key', $idempotencyKey)
            ->where('user_id', $user->id)
            ->first();

        if ($violation === null || ! $violation->confirmed) {
            return false;
        }

        $violation->update(['confirmed' => false, 'action_taken' => 'withdrawn']);
        $this->recompute($user);

        return true;
    }

    /**
     * Re-apply the ladder to whatever points remain.
     *
     * Only ever shortens: a lock the remaining points no longer justify is
     * lifted, and one they still justify is left exactly as it is. It must
     * not extend a penalty, or correcting a mistake could cost the student
     * more than the mistake did.
     *
     * So dropping from a suspension to the restriction rung lifts the
     * suspension and applies no restriction in its place. That is
     * deliberate: the violations that remain were committed in the past
     * and their own windows may long since have elapsed, and starting a
     * fresh 24 hours from now would be punishing someone further for a
     * decision we just admitted was wrong.
     */
    public function recompute(User $user): void
    {
        $consequence = $this->consequenceFor($this->activePoints($user));

        $restriction = $consequence['action'] === 'posting_restriction' && $consequence['hours'] > 0;
        $suspension = $consequence['action'] === 'temporary_suspension' && $consequence['hours'] > 0;

        $updates = [];
        if (! $suspension && $user->banned_until !== null) {
            $updates['banned_until'] = null;
        }
        if (! $restriction && $user->posting_restricted_until !== null) {
            $updates['posting_restricted_until'] = null;
        }
        if ($updates !== [] && $user->banned_at === null) {
            $updates['moderation_status'] = $consequence['action'] === 'none' ? 'clear' : 'warned';
        }

        if ($updates !== []) {
            $user->forceFill($updates)->save();
        }
    }

    /** Confirmed and unexpired only. */
    public function activePoints(User $user): int
    {
        return (int) UserViolation::query()
            ->where('user_id', $user->id)
            ->counting()
            ->sum('points');
    }

    /**
     * What the account is standing at, and what the next point would
     * cost. Used by the admin offender list.
     *
     * @return array{points: int, action: string, hours: int}
     */
    public function standing(User $user): array
    {
        $points = $this->activePoints($user);

        return ['points' => $points] + $this->consequenceFor($points);
    }

    /** @return array{action: string, hours: int} */
    public function consequenceFor(int $points): array
    {
        $result = ['action' => 'none', 'hours' => 0];
        foreach ($this->ladder() as $threshold => $consequence) {
            if ($points >= $threshold) {
                $result = [
                    'action' => (string) ($consequence['action'] ?? 'warning'),
                    'hours' => (int) ($consequence['hours'] ?? 0),
                ];
            }
        }

        return $result;
    }

    /** @return array{points: int, expires_days: int} */
    private function band(string $severity): array
    {
        $bands = (array) config('moderation.enforcement.bands', []);
        $band = $bands[$severity] ?? $bands['minor'] ?? ['points' => 1, 'expires_days' => 90];

        return [
            'points' => (int) ($band['points'] ?? 1),
            'expires_days' => (int) ($band['expires_days'] ?? 90),
        ];
    }

    /** @return array<int, array{action: string, hours: int}> */
    private function ladder(): array
    {
        $ladder = (array) config('moderation.enforcement.ladder', []);
        ksort($ladder);

        return $ladder;
    }

    /** @param array{action: string, hours: int} $consequence */
    private function apply(User $user, array $consequence, string $category): void
    {
        if ($consequence['hours'] <= 0) {
            $user->forceFill([
                'last_violation_at' => now(),
                'moderation_status' => 'warned',
                'moderation_reason' => mb_substr($category, 0, 250),
            ])->save();

            return;
        }

        // A posting restriction and a suspension are different penalties
        // and live in different columns. `EnsureNotBanned` reads only
        // `banned_until`, so a restricted student keeps reading the feed
        // and their messages; the content gate is what refuses them.
        $column = $consequence['action'] === 'posting_restriction'
            ? 'posting_restricted_until'
            : 'banned_until';

        // Never shorten an existing penalty: a new violation during a
        // suspension must not hand back time already being served.
        $until = Carbon::now()->addHours($consequence['hours']);
        $current = $user->{$column};
        if ($current !== null && $current->greaterThan($until)) {
            $until = $current;
        }

        $user->forceFill([
            $column => $until,
            'last_violation_at' => now(),
            'moderation_status' => $consequence['action'] === 'posting_restriction' ? 'restricted' : 'banned',
            'moderation_reason' => mb_substr($category, 0, 250),
        ])->save();
    }
}
