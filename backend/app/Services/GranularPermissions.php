<?php

namespace App\Services;

// The real, checkbox-level permission system explicitly deferred at the
// end of Prompt 3/5 (docs/EKSIKLER.md — "kişi bazında... tek tek override
// edilebilen bir izin sistemi... henüz yok"). Design: additive-only —
// a person's real access is their role's own bucket defaults
// (EnsurePermission::PERMISSIONS) UNION any of these keys explicitly
// granted to them on their RoleAssignment. This can only ever grant a
// person *more* than their role template, never silently take access
// away from someone who already has it via their role — a deliberately
// narrower, safer scope than a full role-replacing permission matrix,
// but every key here is real: checking it, granting it, and persisting it
// all actually work end to end.
class GranularPermissions
{
    // One key per real admin section (after the Prompt 5/5 menu prune),
    // each mapped to the coarse EnsurePermission bucket it satisfies.
    public const KEYS = [
    'dashboard.view' => 'viewAdmin',
    'stats.view' => 'viewAdmin',
    'activities.view' => 'manageContent',
    'activities.create' => 'manageContent',
    'activities.approve' => 'manageContent',
    'activities.reject' => 'manageContent',
    'events.manage' => 'manageContent',
    'pendingActivities.manage' => 'manageContent',
    'clubs.manage' => 'manageContent',
    'sports.manage' => 'manageContent',
    'services.manage' => 'manageContent',
    'food.manage' => 'manageContent',
    'media.manage' => 'manageContent',
    'surveys.manage' => 'manageContent',
    'email.send' => 'viewAdmin',
    'moderation.view' => 'moderate',
    'moderation.moderate' => 'moderate',
    'activityLog.view' => 'viewAdmin',
    'users.view' => 'manageSiteSettings',
    'users.manage' => 'manageSiteSettings',
    'roles.view' => 'manageSiteSettings',
    'roles.manage' => 'manageSiteSettings',
    'siteSettings.view' => 'manageSiteSettings',
    'siteSettings.manage' => 'manageSiteSettings',
    'auditLog.view' => 'viewAdmin',
    ];

    public static function isValidKey(string $key): bool
    {
        return array_key_exists($key, self::KEYS);
    }

    /** @param string[] $keys */
    public static function sanitize(array $keys): array
    {
        return array_values(array_unique(array_filter($keys, fn ($k) => self::isValidKey($k))));
    }

    /** @param string[] $grantedKeys */
    public static function satisfiesBucket(array $grantedKeys, string $bucket): bool
    {
        foreach ($grantedKeys as $key) {
            if ((self::KEYS[$key] ?? null) === $bucket) {
                return true;
            }
        }

        return false;
    }
}
