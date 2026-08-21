<?php

namespace App\Http\Middleware;

use App\Models\RoleAssignment;
use App\Services\GranularPermissions;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

// Real, route-level RBAC enforcement (docs/EKSIKLER.md "RBAC permission
// enforcement — roller var ama route bazında yetki kontrolü yok"): mirrors
// UserRole's canManageContent/canModerate/canManageSiteSettings getters in
// lib/core/models/campus_models.dart exactly, so the Flutter UI's role
// gating and this middleware never disagree about who can do what. Before
// this, every /admin/* route trusted any request that reached it —
// "hidden in the UI" was the only protection, which an API call bypasses
// entirely.
class EnsurePermission
{
    private const PERMISSIONS = [
        // Content CRUD (events/clubs/sports/services/food/directory/pages/
        // surveys/academic years/participation types/attendance).
        'manageContent' => ['superAdmin', 'contentEditor', 'clubManager', 'studentAffairs', 'careerStaff'],
        // Moderation report review.
        'moderate' => ['superAdmin', 'moderator'],
        // Secrets/roles/system settings — the most sensitive bucket.
        'manageSiteSettings' => ['superAdmin'],
        // Read-mostly admin surfaces (stats, audit log, email log) visible
        // to anyone who can reach the Admin Panel at all, same as the
        // Flutter sidebar's own unconditional-once-you're-an-admin items.
        'viewAdmin' => ['superAdmin', 'contentEditor', 'clubManager', 'studentAffairs', 'careerStaff', 'moderator'],
    ];

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();
        $assignment = $user ? RoleAssignment::find($user->email) : null;
        $role = $assignment?->role ?? 'student';
        $allowed = self::PERMISSIONS[$permission] ?? [];

        // Real, additive per-person override (docs/EKSIKLER.md admin §9):
        // a granted checkbox can satisfy a bucket this person's role
        // template alone wouldn't — never the other way around, a role's
        // own baseline access is never reduced by this check.
        $viaOverride = GranularPermissions::satisfiesBucket($assignment?->permissions ?? [], $permission);

        if (! in_array($role, $allowed, true) && ! $viaOverride) {
            return response()->json([
                'data' => null,
                'meta' => ['request_id' => 'req-'.Str::uuid()],
                'error' => ['code' => 'FORBIDDEN', 'message' => 'You do not have permission to perform this action.'],
            ], 403);
        }

        return $next($request);
    }
}
