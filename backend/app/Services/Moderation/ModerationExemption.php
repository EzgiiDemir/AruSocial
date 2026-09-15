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

    /**
     * Surfaces where a member of staff publishes *as the university*.
     *
     * The exemption is scoped to these and nowhere else. The first version
     * exempted staff on every surface, which meant a trainer's personal
     * feed post or story skipped moderation entirely — hate speech
     * published from the accounts the university's own staff use daily.
     * That is not what "do not hold up my announcements" asked for, and
     * it is the worst place to have a blind spot, because staff content
     * carries institutional authority.
     *
     * Matched by prefix on the source feature the controller already
     * declares, so a new admin surface inherits the exemption and a new
     * *social* surface does not.
     */
    private const INSTITUTIONAL_PREFIXES = [
        'admin.',
        'trainer.',
        'media.library',   // the shared library, not a personal gallery
    ];

    /**
     * Whether the moderation pipeline scans this submission.
     *
     * @param  string  $surface  Source feature, e.g. `feed.store`,
     *                           `story.store`, `admin.place.upsert`. An
     *                           unknown or empty surface counts as
     *                           personal: defaulting to exempt is how a
     *                           caller that forgot to pass it would open a
     *                           hole without anyone noticing.
     */
    public static function appliesTo(?User $user, string $surface = ''): bool
    {
        if ($user === null) {
            // No account to attribute the content to. An unattributed
            // submission is the last thing that should skip the check.
            return true;
        }

        // Reads as: moderation applies when the role is one we moderate.
        // This was written with a negation and meant the exact opposite —
        // students exempt, staff scanned — which the tests caught before
        // it reached anything. Kept positive so it cannot invert again.
        if (in_array(GranularPermissions::roleOf($user), self::MODERATED_ROLES, true)) {
            return true;
        }

        // Staff: exempt only where they speak for the institution.
        foreach (self::INSTITUTIONAL_PREFIXES as $prefix) {
            if (str_starts_with($surface, $prefix)) {
                return false;
            }
        }

        return true;
    }

    /** The role that earned the exemption, for the evidence row. */
    public static function reasonFor(?User $user): string
    {
        return $user === null ? 'unknown' : GranularPermissions::roleOf($user);
    }
}
