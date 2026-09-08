<?php

namespace App\Services\Moderation;

use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Turns a strike number into a consequence.
 *
 * The ladder lives in config (services.moderation.penalties) so an
 * administrator can change the rules without a code change:
 *
 *   1-3 → warning
 *   4   → 24-hour ban
 *   5   → 3-day ban
 *   6   → 7-day ban
 *
 * Past the configured end of the ladder the longest configured ban repeats
 * rather than escalating to something permanent on its own — locking an
 * account out forever stays a human decision.
 *
 * Ban windows are computed from server time, never from anything the client
 * sends, so changing a phone's clock cannot shorten a ban.
 */
class PenaltyLadder
{
    /**
     * @return array{action: string, hours: int, banned_until: ?Carbon}
     */
    public function penaltyFor(int $strikeNumber): array
    {
        $ladder = (array) config('services.moderation.penalties', []);
        if ($ladder === []) {
            return ['action' => 'warning', 'hours' => 0, 'banned_until' => null];
        }

        $rule = $ladder[$strikeNumber] ?? null;

        if ($rule === null) {
            $maxStrike = max(array_keys($ladder));
            $rule = config('services.moderation.repeat_last_penalty', true)
                ? $ladder[$maxStrike]
                : ['action' => 'warning', 'hours' => 0];
        }

        $hours = (int) ($rule['hours'] ?? 0);
        $action = (string) ($rule['action'] ?? 'warning');

        return [
            'action' => $action,
            'hours' => $hours,
            'banned_until' => $hours > 0 ? Carbon::now()->addHours($hours) : null,
        ];
    }

    /**
     * Records one strike against $user and applies the matching penalty.
     *
     * @return array{strike: int, action: string, banned_until: ?Carbon}
     */
    public function applyStrike(User $user, string $reason): array
    {
        $user->increment('strikes');
        $user->refresh();

        $strike = (int) $user->strikes;
        $penalty = $this->penaltyFor($strike);

        $updates = [
            'last_violation_at' => Carbon::now(),
            'moderation_reason' => mb_substr($reason, 0, 250),
            'moderation_status' => $penalty['banned_until'] !== null ? 'banned' : 'warned',
        ];

        if ($penalty['banned_until'] !== null) {
            $updates['banned_until'] = $penalty['banned_until'];
        }

        $user->update($updates);

        return [
            'strike' => $strike,
            'action' => $penalty['action'],
            'banned_until' => $penalty['banned_until'],
        ];
    }

    /**
     * True while $user is serving a ban. An expired temporary ban clears
     * itself here, so access is restored without an admin or a cron job.
     */
    public function isCurrentlyBanned(User $user): bool
    {
        if ($user->banned_at !== null) {
            return true;
        }

        $until = $user->banned_until;
        if ($until === null) {
            return false;
        }

        if (Carbon::now()->greaterThanOrEqualTo($until)) {
            $user->update([
                'banned_until' => null,
                'moderation_status' => 'clear',
            ]);

            return false;
        }

        return true;
    }
}
