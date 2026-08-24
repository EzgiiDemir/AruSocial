<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpsertRoleAssignmentRequest;
use App\Models\RoleAssignment;
use App\Services\AuditLogger;
use App\Services\GranularPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Backend-side RoleAssignmentStore — real, shared (not per-device) email
// -> UserRole table. Role stays a plain string matching the real Dart
// UserRole enum (see docs/EKSIKLER.md §11 for why there's no separate
// dynamic roles/permissions table).
class RoleController extends Controller
{
    use ApiResponds;

    private function toJson(RoleAssignment $r): array
    {
        return [
            'email' => $r->email,
            'role' => $r->role,
            'permissions' => $r->permissions ?? [],
            'assignedAt' => $r->assigned_at?->toIso8601String(),
            'assignedBy' => $r->assigned_by,
        ];
    }

    public function index(): JsonResponse
    {
        return $this->ok(RoleAssignment::all()->map(fn ($r) => $this->toJson($r)));
    }

    // Every account calls this for its own address right after signing in,
    // to find out what it may do — so unlike the rest of this controller it
    // can't require users.manage. Instead it's a self-lookup: you may read
    // your own role, and reading anyone else's takes the same permission as
    // changing it. Without that second half this would be an open roster of
    // who the admins are, which is a useful thing for an attacker to know.
    public function roleFor(Request $request, string $email): JsonResponse
    {
        $isSelf = strcasecmp($email, (string) $this->currentUser()->email) === 0;
        if (! $isSelf && ! GranularPermissions::allows($this->currentUser(), 'users.manage')) {
            return $this->fail(403, 'FORBIDDEN', 'Bu işlem için yetkin yok.');
        }

        return $this->ok(['role' => RoleAssignment::find($email)?->role]);
    }

    public function upsert(UpsertRoleAssignmentRequest $request): JsonResponse
    {
        $email = $request->input('email');
        $role = $request->input('role');

        $assignedBy = $request->input('assignedBy', 'admin');
        $attributes = ['role' => $role, 'assigned_by' => $assignedBy, 'assigned_at' => now()];

        // Per-person extras on top of the role template, and only when the
        // caller actually sends the field — omitting it has to leave an
        // existing person's overrides alone rather than silently wiping
        // them. Unknown keys are dropped instead of rejected: they grant
        // nothing either way, and failing the whole role change over a
        // stale key would be worse than ignoring it.
        if ($request->has('permissions')) {
            $attributes['permissions'] = GranularPermissions::sanitize(
                (array) $request->input('permissions', []),
            );
        }

        $assignment = RoleAssignment::updateOrCreate(['email' => $email], $attributes);
        AuditLogger::logAsCurrentUser('role_change', 'user', "$email → $role");

        return $this->ok($this->toJson($assignment));
    }

    public function destroy(Request $request, string $email): JsonResponse
    {
        $assignment = RoleAssignment::find($email);
        if ($assignment) {
            AuditLogger::logAsCurrentUser('delete', 'role_assignment', $email);
            $assignment->delete();
        }

        return $this->ok(['deleted' => true]);
    }
}
