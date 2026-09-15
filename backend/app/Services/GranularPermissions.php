<?php

namespace App\Services;

use App\Models\RoleAssignment;
use App\Models\RoleGrant;
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
    public const ACTIONS = [
        'menu.view', 'list', 'read', 'create', 'update', 'translate', 'preview',
        'submit', 'approve', 'publish', 'unpublish', 'archive', 'soft_delete',
        'restore', 'permanent_delete', 'export', 'export_all', 'bulk_update',
        'bulk_delete', 'manage_settings', 'view_logs',
    ];

    public const SCOPES = [
        'self', 'own', 'assigned', 'own_department', 'own_club', 'own_faculty',
        'own_building', 'campus', 'all',
    ];

    public const ROLES = [
        'superAdmin', 'platformAdmin', 'it', 'clubsDirector', 'clubManager',
        'sportsDirector', 'eventsDirector', 'careerServicesDirector',
        'campusServicesDirector', 'studentAffairs', 'academicUnitManager',
        'teacher', 'psychologicalCounselor', 'libraryManager', 'internationalOffice',
        'dormitoryManager', 'helpCenterManager', 'foodServicesManager',
        'contentEditor', 'translatorTr', 'translatorEn', 'translatorRu',
        'moderator', 'analyst', 'trainer',
    ];

    public const RESOURCES = [
        'pages.page', 'pages.block', 'media.item', 'social.post', 'social.comment',
        'social.message', 'ai.prompt', 'ai.chat_history_metadata', 'ai.chat_history_content',
        'users.user', 'users.role', 'map.place', 'map.building', 'map.coordinate',
        'operations.application', 'operations.appointment', 'events.event', 'clubs.club',
        'sports.sport', 'calendar.entry', 'services.service', 'career.opportunity',
        'food.menu', 'surveys.survey', 'system.email_log', 'system.audit_log',
        'system.integration', 'system.health', 'system.settings',
    ];

    public const RESOURCE_BY_LEGACY_PERMISSION = [
        'events.manage' => 'events.event', 'clubs.manage' => 'clubs.club',
        'places.manage' => 'map.place', 'sports.manage' => 'sports.sport',
        'services.manage' => 'services.service', 'food.manage' => 'food.menu',
        'directory.manage' => 'map.building', 'pages.manage' => 'pages.page',
        'media.manage' => 'media.item', 'career.manage' => 'career.opportunity',
        'surveys.manage' => 'surveys.survey', 'applications.manage' => 'operations.application',
        'appointments.manage' => 'operations.appointment',
    ];

    private const ROLE_RESOURCES = [
        'platformAdmin' => ['*'],
        'it' => ['system.email_log', 'system.audit_log', 'system.integration', 'system.health', 'users.user'],
        'clubsDirector' => ['clubs.club', 'events.event', 'operations.application', 'media.item', 'pages.page'],
        'clubManager' => ['clubs.club', 'events.event', 'operations.application', 'media.item'],
        'sportsDirector' => ['sports.sport', 'events.event', 'operations.application', 'calendar.entry'],
        'eventsDirector' => ['events.event', 'operations.application', 'calendar.entry', 'media.item'],
        'careerServicesDirector' => ['career.opportunity', 'operations.application', 'operations.appointment'],
        'campusServicesDirector' => ['services.service', 'map.place', 'map.building', 'map.coordinate', 'food.menu'],
        'studentAffairs' => ['users.user', 'operations.application', 'operations.appointment', 'pages.page'],
        'academicUnitManager' => ['pages.page', 'events.event', 'calendar.entry', 'users.user'],
        'teacher' => ['pages.page', 'events.event', 'clubs.club'],
        'psychologicalCounselor' => ['operations.appointment'],
        'libraryManager' => ['services.service', 'events.event', 'operations.appointment', 'pages.page'],
        'internationalOffice' => ['pages.page', 'operations.application', 'operations.appointment'],
        'dormitoryManager' => ['map.building', 'services.service', 'operations.application', 'operations.appointment', 'events.event'],
        'helpCenterManager' => ['services.service'],
        'foodServicesManager' => ['food.menu', 'services.service'],
        'contentEditor' => ['pages.page', 'pages.block', 'media.item', 'events.event', 'clubs.club', 'sports.sport', 'services.service', 'career.opportunity', 'food.menu'],
        'translatorTr' => ['pages.page', 'pages.block'], 'translatorEn' => ['pages.page', 'pages.block'], 'translatorRu' => ['pages.page', 'pages.block'],
        'moderator' => ['social.post', 'social.comment'],
        'analyst' => ['system.audit_log', 'system.email_log', 'events.event', 'clubs.club', 'sports.sport', 'operations.application'],
        'trainer' => ['events.event'],
    ];

    private const ROLE_ACTIONS = [
        'platformAdmin' => ['menu.view', 'list', 'read', 'create', 'update', 'translate', 'preview', 'submit', 'approve', 'publish', 'unpublish', 'archive', 'soft_delete', 'restore', 'export', 'export_all', 'bulk_update', 'bulk_delete', 'manage_settings', 'view_logs'],
        'it' => ['menu.view', 'list', 'read', 'update', 'manage_settings', 'view_logs'],
        'contentEditor' => ['menu.view', 'list', 'read', 'create', 'update', 'translate', 'preview', 'submit', 'archive', 'soft_delete', 'restore'],
        'teacher' => ['menu.view', 'list', 'read', 'create', 'update', 'translate', 'preview', 'submit'],
        'translatorTr' => ['menu.view', 'list', 'read', 'translate', 'preview'],
        'translatorEn' => ['menu.view', 'list', 'read', 'translate', 'preview'],
        'translatorRu' => ['menu.view', 'list', 'read', 'translate', 'preview'],
        'moderator' => ['menu.view', 'list', 'read', 'update', 'approve', 'archive', 'view_logs'],
        'analyst' => ['menu.view', 'list', 'read', 'preview', 'export', 'view_logs'],
        'trainer' => ['menu.view', 'list', 'read', 'create', 'update', 'preview', 'submit'],
    ];

    // One key per real admin section, mapped to the bucket that satisfies
    // it. Derived from what routes/api.php actually exposes — a key with no
    // endpoint behind it would be a promise nothing keeps.
    public const KEYS = [
        'events.manage' => 'manageContent',
        'pendingActivities.manage' => 'manageContent',
        'clubs.manage' => 'manageContent',
        'places.manage' => 'manageContent',
        'sports.manage' => 'manageContent',
        'services.manage' => 'manageContent',
        'food.manage' => 'manageContent',
        'directory.manage' => 'manageContent',
        'pages.manage' => 'manageContent',
        'media.manage' => 'manageContent',
        'career.manage' => 'campusOps',
        'surveys.manage' => 'manageContent',
        'academicYears.manage' => 'manageContent',
        'staff.manage' => 'manageContent',
        'applications.manage' => 'campusOps',
        'appointments.manage' => 'campusOps',
        'achievements.manage' => 'manageContent',
        'shuttle.manage' => 'manageContent',
        'onboarding.manage' => 'manageContent',
        'moderation.moderate' => 'moderate',
        'email.send' => 'viewAdmin',
        'stats.view' => 'viewAdmin',
        'activityLog.view' => 'viewAdmin',
        'users.manage' => 'manageSiteSettings',
        // Trainer Panel: a department head publishing events/activities
        // scoped to their own department only — deliberately its own key
        // rather than folded into 'events.manage' (which is
        // department-unscoped, full-admin content management). Row-level
        // "which department" scoping is enforced by the 'department-head'
        // middleware (see EnsureDepartmentHead), not by this permission
        // check — this key only answers "is this account provisioned as a
        // trainer at all."
        'events.manageOwnDepartment' => 'manageOwnDepartment',
    ];

    // Mirrors UserRoleLabel's getters in campus_models.dart exactly.
    private const ROLE_BUCKETS = [
        // Content CRUD: events, clubs, sports, services, food, directory,
        // pages, surveys, academic years, participation types, attendance.
        'manageContent' => ['platformAdmin', 'contentEditor', 'clubsDirector', 'clubManager', 'sportsDirector', 'eventsDirector', 'campusServicesDirector', 'studentAffairs', 'academicUnitManager', 'careerStaff', 'careerServicesDirector', 'libraryManager', 'internationalOffice', 'dormitoryManager', 'foodServicesManager'],
        // Career office + appointments + applications: career staff AND
        // trainers (department heads) in addition to the usual content roles.
        'campusOps' => ['platformAdmin', 'contentEditor', 'clubManager', 'studentAffairs', 'careerStaff', 'careerServicesDirector', 'eventsDirector', 'sportsDirector', 'trainer'],
        // Reviewing reported content and the moderation service settings.
        'moderate' => ['platformAdmin', 'moderator'],
        // Roles and secrets — the most sensitive bucket, super admin only.
        'manageSiteSettings' => [],
        // Read-mostly admin surfaces (stats, audit log, email log), open to
        // anyone who can reach the Admin Panel at all.
        'viewAdmin' => ['platformAdmin', 'it', 'contentEditor', 'clubManager', 'studentAffairs', 'careerStaff', 'moderator', 'analyst'],
        // Trainer Panel — department heads/teachers/staff managing only
        // their own department's events, not the full admin surface.
        'manageOwnDepartment' => ['trainer'],
    ];

    // The one central super-admin rule. Deliberately a single named
    // constant checked in exactly one place (below) rather than
    // `superAdmin` being repeated into every bucket list, so "who bypasses
    // everything" stays one obvious line instead of an invariant spread
    // across a table someone could edit half of.
    public const SUPER_ROLE = 'superAdmin';

    public static function isValidKey(string $key): bool
    {
        return array_key_exists($key, self::KEYS)
            || preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*\.('.implode('|', array_map(fn ($a) => preg_quote($a, '/'), self::ACTIONS)).')(?:\.('.implode('|', self::SCOPES).'))?$/', $key) === 1;
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

        $grants = RoleGrant::query()->active()->where('user_id', $user->id)->get();

        if ($grants->contains(fn (RoleGrant $grant) => in_array($permission, $grant->denied_permissions ?? [], true))) {
            return false;
        }

        if ($grants->contains(fn (RoleGrant $grant) => $grant->role === self::SUPER_ROLE)) {
            return true;
        }

        if ($grants->contains(fn (RoleGrant $grant) => self::grantAllows($grant, $permission))) {
            return true;
        }

        if ($role === self::SUPER_ROLE) {
            return true;
        }

        // An unknown key is denied rather than ignored: a typo in a route's
        // middleware argument must fail closed, not silently open the route.
        $bucket = self::KEYS[$permission] ?? null;
        if ($bucket === null) {
            return false;
        }

        return self::legacyAllows($user, $permission);
    }

    /**
     * The application has one back-office panel. A role may enter it when
     * it owns at least one real capability; resources still enforce their
     * own actions and row-level scopes after entry.
     */
    public static function canAccessAdminPanel(User $user): bool
    {
        foreach (array_keys(self::KEYS) as $permission) {
            if (self::allows($user, $permission)) {
                return true;
            }
        }

        foreach (self::RESOURCES as $resource) {
            if (self::allows($user, "{$resource}.menu.view")) {
                return true;
            }
        }

        return false;
    }

    public static function legacyAllows(User $user, string $permission): bool
    {
        $assignment = RoleAssignment::find($user->email);
        $role = $assignment?->role ?? 'student';
        if ($role === self::SUPER_ROLE) {
            return true;
        }
        $bucket = self::KEYS[$permission] ?? null;
        if ($bucket !== null && in_array($role, self::ROLE_BUCKETS[$bucket] ?? [], true)) {
            return true;
        }

        return in_array($permission, self::sanitize($assignment?->permissions ?? []), true);
    }

    public static function grantAllows(RoleGrant $grant, string $permission): bool
    {
        if ($grant->role === self::SUPER_ROLE) {
            return true;
        }
        if ((str_contains($permission, '.publish') || str_contains($permission, '.unpublish')) && ! $grant->can_publish) {
            return false;
        }
        if ((str_contains($permission, '.export') || str_contains($permission, '.export_all')) && ! $grant->can_export) {
            return false;
        }
        if (str_starts_with($permission, 'ai.chat_history_content.') && ! $grant->sensitive_data_access) {
            return false;
        }
        if (in_array($permission, $grant->permissions ?? [], true)) {
            return true;
        }

        $resource = self::RESOURCE_BY_LEGACY_PERMISSION[$permission] ?? null;
        if ($resource !== null) {
            return self::roleHasResource($grant->role, $resource);
        }

        if ($permission === 'moderation.moderate') {
            return in_array($grant->role, ['platformAdmin', 'moderator'], true);
        }
        if (in_array($permission, ['stats.view', 'activityLog.view'], true)) {
            return in_array($grant->role, ['platformAdmin', 'it', 'analyst'], true);
        }
        if ($permission === 'users.manage') {
            return false;
        }

        foreach (self::ACTIONS as $action) {
            $suffix = '.'.$action;
            if (! str_ends_with($permission, $suffix)) {
                continue;
            }
            $canonicalResource = substr($permission, 0, -strlen($suffix));

            return self::roleHasResource($grant->role, $canonicalResource)
                && in_array($action, self::actionsForRole($grant->role), true);
        }

        return false;
    }

    private static function roleHasResource(string $role, string $resource): bool
    {
        $resources = self::ROLE_RESOURCES[$role] ?? [];

        return in_array('*', $resources, true) || in_array($resource, $resources, true);
    }

    private static function actionsForRole(string $role): array
    {
        return self::ROLE_ACTIONS[$role] ?? [
            'menu.view', 'list', 'read', 'create', 'update', 'translate', 'preview',
            'submit', 'approve', 'publish', 'unpublish', 'archive', 'soft_delete',
            'restore', 'export', 'bulk_update', 'manage_settings', 'view_logs',
        ];
    }

    public static function roleOptions(): array
    {
        return collect(self::ROLES)->mapWithKeys(fn (string $role) => [$role => str($role)->headline()->toString()])->all();
    }

    public static function permissionOptions(): array
    {
        $options = collect(self::KEYS)->keys()->mapWithKeys(fn (string $key) => [$key => $key]);

        foreach (self::RESOURCES as $resource) {
            foreach (self::ACTIONS as $action) {
                $key = "{$resource}.{$action}";
                $options[$key] = $key;
            }
        }

        return $options->all();
    }

    public static function actionPermission(User $user, string $legacyPermission, string $action): string
    {
        $resource = self::RESOURCE_BY_LEGACY_PERMISSION[$legacyPermission] ?? null;
        $canonical = $resource ? "{$resource}.{$action}" : null;
        if ($canonical !== null && ! self::legacyAllows($user, $legacyPermission)
            && RoleGrant::query()->active()->where('user_id', $user->id)->exists()) {
            return $canonical;
        }
        if ($canonical !== null && self::allows($user, $canonical)) {
            return $canonical;
        }

        return $legacyPermission;
    }
}
