<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\FeedPost;
use App\Models\PostComment;
use App\Models\RoleAssignment;
use App\Models\SocialFollow;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\GranularPermissions;
use App\Services\RealtimePublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

// Real "Kullanıcılar ve Roller" backing (docs/EKSIKLER.md admin §9) — a
// list of genuine `users` rows (not just the pre-provisioned email->role
// mapping RoleController already exposed), each with its real role,
// granular permission overrides, active/banned status, and real usage
// counters. RoleController/role_assignments stays exactly as it was for
// backward compatibility; this controller is additive.
class UserController extends Controller
{
    use ApiResponds;

    private function toJson(User $u): array
    {
        $assignment = RoleAssignment::find($u->email);

        return [
            'id' => (string) $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'role' => $assignment?->role ?? 'student',
            'permissions' => $assignment?->permissions ?? [],
            'active' => ! $u->isDeactivated(),
            'banned' => $u->isBanned(),
            'strikes' => $u->strikes,
            'xp' => $u->xp,
            'checkins' => $u->places,
            'eventsJoined' => $u->events,
            'createdAt' => $u->created_at?->toIso8601String(),
        ];
    }

    public function index(): JsonResponse
    {
        $users = User::orderByDesc('created_at')->get();

        return $this->ok($users->map(fn ($u) => $this->toJson($u)));
    }

    public function show(string $id): JsonResponse
    {
        $user = User::find($id);
        if (! $user) {
            return $this->fail(404, 'USER_NOT_FOUND', 'User not found.');
        }

        return $this->ok([
            ...$this->toJson($user),
            // Real, extra detail for the admin's "detaylı kullanıcı
            // bilgisi" view — not part of the list payload (would be N+1
            // expensive across every row), computed only for one user here.
            'postsCount' => FeedPost::where('author_id', (string) $user->id)->count(),
            'commentsCount' => PostComment::where('author', $user->name)->count(),
            'followersCount' => SocialFollow::where('followed_name', $user->name)->count(),
            'followingCount' => SocialFollow::where('follower_user_id', $user->id)->count(),
        ]);
    }

    // Real account pre-provisioning (docs/EKSIKLER.md admin §9,
    // "kullanıcı oluşturabilmeli") — same shape AuthController::session()
    // already uses to lazily create a real account on first sign-in, just
    // triggered by an admin ahead of time instead. No invite/e-mail
    // system exists yet, so the temporary password is real but only ever
    // usable through a real deployment's own password-reset flow — this
    // prototype's own sign-in path doesn't check it (Mock mode never
    // touches the backend; Rest mode issues a session by e-mail alone,
    // see AuthController::session()).
    public function store(Request $request): JsonResponse
    {
        $email = strtolower(trim((string) $request->input('email', '')));
        $name = trim((string) $request->input('name', ''));
        if (! $email || ! str_ends_with($email, '@arucad.edu.tr') || ! $name) {
            return $this->fail(400, 'VALIDATION', 'A real name and an @arucad.edu.tr email are required.');
        }
        if (User::where('email', $email)->exists()) {
            return $this->fail(409, 'USER_EXISTS', 'A user with that email already exists.');
        }

        $role = $request->input('role', 'student');
        $validRoles = ['student', 'clubManager', 'contentEditor', 'moderator', 'careerStaff', 'studentAffairs', 'superAdmin'];
        if (! in_array($role, $validRoles, true)) {
            return $this->fail(400, 'VALIDATION', 'role must be a valid UserRole.');
        }
        $permissions = GranularPermissions::sanitize((array) $request->input('permissions', []));

        $user = User::create(['name' => $name, 'email' => $email, 'password' => bcrypt(Str::random(40))]);
        $actor = $this->currentUser()->name;
        RoleAssignment::updateOrCreate(
            ['email' => $email],
            ['role' => $role, 'permissions' => $permissions, 'assigned_by' => $actor, 'assigned_at' => now()]
        );
        AuditLogger::log($actor, 'create', 'user', "$name ($email) → $role");

        return $this->ok($this->toJson($user->fresh()), 201);
    }

    // Role/permissions/active-state edits — everything except identity
    // (name/email don't change here; that's not what this phase asked for).
    public function update(Request $request, string $id): JsonResponse
    {
        $user = User::find($id);
        if (! $user) {
            return $this->fail(404, 'USER_NOT_FOUND', 'User not found.');
        }
        $actor = $this->currentUser();

        if ($request->has('active')) {
            $active = (bool) $request->input('active');
            $user->update(['deactivated_at' => $active ? null : now()]);
            AuditLogger::log($actor->name, $active ? 'activate' : 'deactivate', 'user', $user->name);
        }

        if ($request->has('role') || $request->has('permissions')) {
            $validRoles = ['student', 'clubManager', 'contentEditor', 'moderator', 'careerStaff', 'studentAffairs', 'superAdmin'];
            $existing = RoleAssignment::find($user->email);
            $role = $request->input('role', $existing?->role ?? 'student');
            if (! in_array($role, $validRoles, true)) {
                return $this->fail(400, 'VALIDATION', 'role must be a valid UserRole.');
            }
            $permissions = $request->has('permissions')
                ? GranularPermissions::sanitize((array) $request->input('permissions', []))
                : ($existing?->permissions ?? []);
            RoleAssignment::updateOrCreate(
                ['email' => $user->email],
                ['role' => $role, 'permissions' => $permissions, 'assigned_by' => $actor->name, 'assigned_at' => now()]
            );
            AuditLogger::log($actor->name, 'role_change', 'user', "{$user->email} → $role");
            RealtimePublisher::toUser((string) $user->id, 'role.updated', 'user', (string) $user->id);
            RealtimePublisher::toUser((string) $user->id, 'permission.updated', 'user', (string) $user->id);
        }

        return $this->ok($this->toJson($user->fresh()));
    }

    // Real, separate "banned accounts" category (docs/EKSIKLER.md admin
    // §10, "Banlı/yasaklı hesaplar ayrı kategoride tutulmalı") — distinct
    // from ModerationReport (a report can be resolved without anyone ever
    // being banned, and a ban here is ModerationService's automatic
    // 3-strike consequence, not a per-report action).
    public function bannedIndex(): JsonResponse
    {
        $users = User::whereNotNull('banned_at')->orderByDesc('banned_at')->get();

        return $this->ok($users->map(fn ($u) => $this->toJson($u)));
    }

    // Unban is a real fresh start, not just clearing the flag: strikes
    // reset to 0 too, otherwise the very next single new violation would
    // immediately re-trip ModerationService's ">= 3 strikes" threshold
    // again (since it would already be sitting at 3).
    public function unban(string $id): JsonResponse
    {
        $user = User::find($id);
        if (! $user) {
            return $this->fail(404, 'USER_NOT_FOUND', 'User not found.');
        }
        $user->update(['banned_at' => null, 'strikes' => 0]);
        AuditLogger::log($this->currentUser()->name, 'unban', 'user', $user->name);

        return $this->ok($this->toJson($user->fresh()));
    }
}
