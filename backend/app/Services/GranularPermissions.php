<?php

namespace App\Services;

use App\Models\RoleAssignment;
use App\Models\User;

// Who is allowed to do what, in one place.
//
// Two layers, because the product already has two:
//
//  1. A role template. The roles are the existing product roles (the same
//     seven the Dart `UserRole` enum defines), and each one maps to the
//     coarse capability buckets `UserRole`'s own getters already express in
//     lib/core/models/campus_models.dart — canManageContent, canModerate,
//     canManageSiteSettings. Keeping the two definitions identical is the
//     point: if they disagreed, the admin panel would show buttons the API
//     then refuses, or hide ones it would have allowed.
//
//  2. Per-endpoint permission keys. Each real admin section has its own key
//     (KEYS below), so enforcement happens per endpoint rather than through
//     a single "is admin" flag. A key is granted either because the
//     person's role covers that key's bucket, or because that exact key was
//     granted to them individually on their RoleAssignment.
//
// The per-person layer is additive only: it can give someone more than
// their role template, never quietly take away access their role already
// grants. That's a deliberately narrower, safer scope than a full
// role-replacing matrix — and unlike the previous state of this codebase,
// every key here is really checked on a real route.
class GranularPermissions
{
    // One key per real admin section, mapped to the bucket that satisfies
    // it. Derived from what routes/api.php actually exposes — a key with no
    // endpoint behind it would be a promise nothing keeps.
    public const KEYS = [
        'events.manage' => 'manageContent',
        'pendingActivities.manage' => 'manageContent',
        'clubs.manage' => 'manageContent',
        'sports.manage' => 'manageContent',
        'services.manage' => 'manageContent',
        'food.manage' => 'manageContent',
        'directory.manage' => 'manageContent',
        'pages.manage' => 'manageContent',
        'media.manage' => 'manageContent',
        'surveys.manage' => 'manageContent',
        'academicYears.manage' => 'manageContent',
        'moderation.moderate' => 'moderate',
        'email.send' => 'viewAdmin',
        'stats.view' => 'viewAdmin',
        'activityLog.view' => 'viewAdmin',
        'users.manage' => 'manageSiteSettings',
    ];

    // Mirrors UserRoleLabel's getters in campus_models.dart exactly.
    private const ROLE_BUCKETS = [
        // Content CRUD: events, clubs, sports, services, food, directory,
        // pages, surveys, academic years, participation types, attendance.
        'manageContent' => ['contentEditor', 'clubManager', 'studentAffairs', 'careerStaff'],
        // Reviewing reported content and the moderation service settings.
        'moderate' => ['moderator'],
        // Roles and secrets — the most sensitive bucket, super admin only.
        'manageSiteSettings' => [],
        // Read-mostly admin surfaces (stats, audit log, email log), open to
        // anyone who can reach the Admin Panel at all.
        'viewAdmin' => ['contentEditor', 'clubManager', 'studentAffairs', 'careerStaff', 'moderator'],
    ];

    // The one central super-admin rule. Deliberately a single named
    // constant checked in exactly one place (below) rather than
    // `superAdmin` being repeated into every bucket list, so "who bypasses
    // everything" stays one obvious line instead of an invariant spread
    // across a table someone could edit half of.
    public const SUPER_ROLE = 'superAdmin';

    public static function isValidKey(string $key): bool
    {
        return array_key_exists($key, self::KEYS);
    }

    /**
     * @param  string[]  $keys
     * @return string[]
     */
    public static function sanitize(array $keys): array
    {
        return array_values(array_unique(array_filter(
            $keys,
            fn ($k) => is_string($k) && self::isValidKey($k),
        )));
    }

    /**
     * The role this account is authorized as. `role_assignments` is the
     * single source of truth here — `users.role` is a display field on the
     * profile, and letting it grant access too would mean two places to get
     * wrong. No assignment means no elevated access at all.
     */
    public static function roleOf(User $user): string
    {
        return RoleAssignment::find($user->email)?->role ?? 'student';
    }

    public static function allows(User $user, string $permission): bool
    {
        $assignment = RoleAssignment::find($user->email);
        $role = $assignment?->role ?? 'student';

        if ($role === self::SUPER_ROLE) {
            return true;
        }

        // An unknown key is denied rather than ignored: a typo in a route's
        // middleware argument must fail closed, not silently open the route.
        $bucket = self::KEYS[$permission] ?? null;
        if ($bucket === null) {
            return false;
        }

        if (in_array($role, self::ROLE_BUCKETS[$bucket] ?? [], true)) {
            return true;
        }

        return in_array($permission, self::sanitize($assignment?->permissions ?? []), true);
    }
}
