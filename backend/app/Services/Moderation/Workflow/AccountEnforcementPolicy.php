<?php

namespace App\Services\Moderation\Workflow;

use App\Models\User;
use App\Models\UserViolation;
use App\Services\Moderation\AccountEnforcement;
use Illuminate\Support\Str;

/**
 * What a confirmed violation costs an account.
 *
 * Separate from content moderation on purpose. A classifier decides
 * whether a photo is published; this decides whether a person keeps
 * posting, and the second question needs a human in the loop and a much
 * higher bar.
 *
 * Three rules the old single ladder got wrong:
 *
 *  - Severity matters. Spam and a credible threat cannot share a
 *    punishment path just because both count as "one violation".
 *  - REVIEW costs nothing. It means the system was unsure, and charging
 *    someone for our uncertainty is how you lose the users you built
 *    this for.
 *  - Points expire. A ladder with no decay eventually bans everyone who
 *    stays long enough.
 */
final class AccountEnforcementPolicy
{
    /**
     * Points per confirmed violation, and how long they count for.
     *
     * @var array<string, array{points: int, expires_days: int}>
     */
    private const BANDS = [
        'minor' => ['points' => 1, 'expires_days' => 90],
        'serious' => ['points' => 3, 'expires_days' => 180],
        'severe' => ['points' => 6, 'expires_days' => 365],
        'critical' => ['points' => 12, 'expires_days' => 730],
    ];

    /**
     * Accumulated points to consequence.
     *
     * Read as "at least this many points" — highest matching rung wins.
     * Nothing here bans permanently: the ceiling is a long suspension,
     * and making it permanent stays an explicit human decision recorded
     * against a named moderator.
     *
     * @var array<int, array{action: string, hours: int}>
     */
    private const LADDER = [
        1 => ['action' => 'warning', 'hours' => 0],
        3 => ['action' => 'posting_restriction', 'hours' => 24],
        6 => ['action' => 'temporary_suspension', 'hours' => 72],
        12 => ['action' => 'temporary_suspension', 'hours' => 168],
        20 => ['action' => 'temporary_suspension', 'hours' => 720],
    ];

    /**
     * Record a confirmed violation and apply the resulting consequence.
     *
     * `idempotencyKey` is what makes a retried job or a double-clicked
     * moderator button safe: the same act cannot be punished twice, and
     * a repeat call returns the original decision unchanged.
     *
     * @return array{violation: UserViolation, action: string, hours: int, points: int, duplicate: bool}
     */
    public function recordConfirmedViolation(
        User $user,
        string $category,
        string $severity,
        string $idempotencyKey,
        ?string $caseId = null,
        ?int $decidedBy = null,
    ): array {
        $band = self::BANDS[$severity] ?? self::BANDS['minor'];

        $existing = UserViolation::where('idempotency_key', $idempotencyKey)->first();
        if ($existing !== null) {
            return [
                'violation' => $existing,
                'action' => (string) $existing->action_taken,
                'hours' => 0,
                'points' => $this->activePoints($user),
                'duplicate' => true,
            ];
        }

        $violation = UserViolation::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'moderation_case_id' => $caseId,
            'category' => $category,
            'severity' => $severity,
            'confirmed' => true,
            'points' => $band['points'],
            'decided_by' => $decidedBy,
            'idempotency_key' => $idempotencyKey,
            'expires_at' => now()->addDays($band['expires_days']),
        ]);

        $points = $this->activePoints($user);
        $consequence = $this->consequenceFor($points);

        $violation->update(['action_taken' => $consequence['action']]);
        $this->apply($user, $consequence);

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
            'points' => $points,
            'duplicate' => false,
        ];
    }

    /** Confirmed and unexpired only. */
    public function activePoints(User $user): int
    {
        return (int) UserViolation::query()
            ->where('user_id', $user->id)
            ->counting()
            ->sum('points');
    }

    /** @return array{action: string, hours: int} */
    public function consequenceFor(int $points): array
    {
        $result = ['action' => 'none', 'hours' => 0];
        foreach (self::LADDER as $threshold => $consequence) {
            if ($points >= $threshold) {
                $result = $consequence;
            }
        }

        return $result;
    }

    /** @param array{action: string, hours: int} $consequence */
    private function apply(User $user, array $consequence): void
    {
        if ($consequence['hours'] <= 0) {
            return;
        }

        // The violation row above is still written: it is the case
        // record moderators work from, and suppressing it would break the
        // queue rather than the punishment. Only the lock is skipped.
        if (! AccountEnforcement::enabled()) {
            AccountEnforcement::skip($user,
                "{$consequence['action']} for {$consequence['hours']}h");

            return;
        }

        // Never shorten an existing penalty: a new violation during a
        // suspension must not hand back time already being served.
        $until = now()->addHours($consequence['hours']);
        $current = $user->banned_until;
        if ($current !== null && $current->greaterThan($until)) {
            return;
        }

        $user->forceFill([
            'banned_until' => $until,
            'last_violation_at' => now(),
        ])->save();
    }
}
