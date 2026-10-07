<?php

namespace App\Services\Moderation;

use App\Models\User;
use App\Services\AuditLogger;

/**
 * The switch between "this content is refused" and "this account pays
 * for it".
 *
 * Content moderation and account enforcement are different decisions with
 * different stakes — refusing a photo is reversible in a second, locking
 * a student out of the campus app is not — so they are separately
 * switchable. This gate covers only the second one. Nothing here can make
 * unsafe content publish.
 *
 * There is now exactly one path that mutates account state, and it
 * consults this:
 *
 *  - `AccountEnforcementPolicy::recordConfirmedViolation` (points ->
 *    warning, posting restriction or suspension)
 *
 * There used to be three, with three different rules, and a gate applied
 * to two of them was not a gate. If a second path ever appears, it
 * belongs behind this one.
 */
final class AccountEnforcement
{
    /** Defaults to on; see config/moderation.php for why. */
    public static function enabled(): bool
    {
        return (bool) config('moderation.enforcement.enabled', true);
    }

    /**
     * Record a consequence that was suppressed, and say what it was.
     *
     * The audit trail is the reason this is a function rather than an
     * early `return`. A suspended enforcement window that leaves no
     * evidence is indistinguishable afterwards from an enforcement bug,
     * and "why was nobody ever sanctioned in October" needs an answer
     * that is written down at the time.
     */
    public static function skip(User $user, string $wouldHave): void
    {
        AuditLogger::log('system', 'moderation_enforcement_skipped', 'user',
            "{$user->name}: enforcement disabled — {$wouldHave}");
    }
}
