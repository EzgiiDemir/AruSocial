<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\RoleAssignment;
use App\Services\AuditLogger;
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
            'assignedAt' => $r->assigned_at?->toIso8601String(),
            'assignedBy' => $r->assigned_by,
        ];
    }

    public function index(): JsonResponse
    {
        return $this->ok(RoleAssignment::all()->map(fn ($r) => $this->toJson($r)));
    }

    public function roleFor(string $email): JsonResponse
    {
        $assignment = RoleAssignment::find($email);

        return $this->ok(['role' => $assignment?->role]);
    }

    public function upsert(Request $request): JsonResponse
    {
        $email = $request->input('email');
        $role = $request->input('role');
        $validRoles = ['student', 'clubManager', 'contentEditor', 'moderator', 'careerStaff', 'studentAffairs', 'superAdmin'];
        if (! $email || ! in_array($role, $validRoles, true)) {
            return $this->fail(400, 'VALIDATION', 'email is required and role must be a valid UserRole.');
        }

        $assignedBy = $request->input('assignedBy', 'admin');
        $assignment = RoleAssignment::updateOrCreate(
            ['email' => $email],
            ['role' => $role, 'assigned_by' => $assignedBy, 'assigned_at' => now()]
        );
        AuditLogger::log($assignedBy, 'role_change', 'user', "$email → $role");

        return $this->ok($this->toJson($assignment));
    }

    public function destroy(Request $request, string $email): JsonResponse
    {
        $assignment = RoleAssignment::find($email);
        if ($assignment) {
            AuditLogger::log($request->input('actorName', 'admin'), 'delete', 'role_assignment', $email);
            $assignment->delete();
        }

        return $this->ok(['deleted' => true]);
    }
}
