<?php

namespace App\Services\Moderation;

use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Whether an account is currently locked, and out of what.
 *
 * Two different locks, deliberately not collapsed into one:
 *
 *  - **Suspension** (`banned_until`, or `banned_at` for a permanent ban
 *    an administrator set): no API access at all. Enforced for every
 *    request by `EnsureNotBanned`.
 *  - **Posting restriction** (`posting_restricted_until`): may not submit
 *    content. Reading the feed, messages, appointments and everything
 *    else continues to work. Enforced by `ContentModerator`, the one gate
 *    every submission passes through.
 *
 * This class used to be `PenaltyLadder` and also owned a strike ladder.
 * That ladder is gone: `AccountEnforcementPolicy` and the points in
 * `config/moderation.php` are now the only thing that decides what a
 * violation costs. What is left here is the question "is this account
 * locked right now", which is a different one and is asked on every
 * request.
 *
 * Windows are computed from server time, never from anything the client
 * sends, so changing a phone's clock cannot shorten a penalty.
 */
class AccountStanding
{
    /**
     * True while $user is serving a suspension. An expired temporary one
     * clears itself here, so access is restored without an admin or a
     * cron job.
     */
    public function isCurrentlyBanned(User $user): bool
    {
        if ($user->banned_at !== null) {
            return true;
        }

        return $this->serving($user, 'banned_until');
    }

    /**
     * True while $user may not submit content. Same self-clearing rule.
     *
     * A suspension implies this: someone locked out of the API entirely
     * certainly cannot post. Callers that check both get a consistent
     * answer whichever they ask first.
     */
    public function isPostingRestricted(User $user): bool
    {
        if ($this->isCurrentlyBanned($user)) {
            return true;
        }

        return $this->serving($user, 'posting_restricted_until');
    }

    /** When the current posting restriction ends, if one is running. */
    public function postingRestrictedUntil(User $user): ?Carbon
    {
        return $this->isPostingRestricted($user) ? $user->posting_restricted_until : null;
    }

    private function serving(User $user, string $column): bool
    {
        $until = $user->{$column};
        if ($until === null) {
            return false;
        }

        if (Carbon::now()->greaterThanOrEqualTo($until)) {
            $user->forceFill([
                $column => null,
                'moderation_status' => $this->stillLocked($user, $column) ? $user->moderation_status : 'clear',
            ])->save();

            return false;
        }

        return true;
    }

    /** Is the *other* lock still running, so the status must not be cleared? */
    private function stillLocked(User $user, string $cleared): bool
    {
        if ($user->banned_at !== null) {
            return true;
        }

        $other = $cleared === 'banned_until' ? 'posting_restricted_until' : 'banned_until';
        $until = $user->{$other};

        return $until !== null && $until->greaterThan(Carbon::now());
    }
}
