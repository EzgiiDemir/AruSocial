<?php

namespace App\Services\Moderation;

use App\Models\User;
use App\Services\GranularPermissions;

/**
 * Who the student moderation pipeline applies to.
 *
 * Student content is scanned. Content published by teachers, department
 * heads and administrators is not: an announcement written by staff is
 * institutional communication, and holding it in a review queue behind a
 * classifier means the university cannot reliably publish its own
 * notices.
 *
 * Three properties this deliberately has:
 *
 *  - It is decided from the server-side role assignment, never from
 *    anything the client sends. A request cannot claim to be staff.
 *  - It is one function. The rule being in a single place is the whole
 *    point — an exemption spread across controllers is one somebody
 *    eventually applies to the wrong surface.
 *  - It exempts from *scanning*, not from accountability. An exempt
 *    submission still records an evidence row saying it was exempt and
 *    why, so "who published this" stays answerable. Bans still apply:
 *    a suspended account cannot post regardless of role.
 */
final class ModerationExemption
{
    /**
     * The only role the pipeline scans.
     *
     * Written as an allowlist of the scanned role rather than a blocklist
     * of exempt ones: a role added later defaults to being *scanned*,
     * which is the safe direction to fail. A blocklist would silently
     * exempt every new role somebody invents.
     */
    private const MODERATED_ROLES = ['student'];

    public static function appliesTo(?User $user): bool
    {
        if ($user === null) {
            // No account to attribute the content to. Not staff, so it is
            // scanned — an unattributed submission is the last thing that
            // should skip the check.
            return true;
        }

        // Reads as: moderation applies when the role is one we moderate.
        // This was written with a negation and meant the exact opposite —
        // students exempt, staff scanned — which the tests caught before
        // it reached anything. Kept positive so it cannot invert again.
        return in_array(GranularPermissions::roleOf($user), self::MODERATED_ROLES, true);
    }

    /** The role that earned the exemption, for the evidence row. */
    public static function reasonFor(?User $user): string
    {
        return $user === null ? 'unknown' : GranularPermissions::roleOf($user);
    }
}
