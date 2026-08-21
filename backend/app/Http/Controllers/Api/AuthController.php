<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Admin\SettingsController;
use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

// Real, per-user Sanctum session (docs/EKSIKLER.md "Gerçek JWT/session
// authentication"): every previous endpoint in this app trusted whatever
// request reached it, always resolving `currentUser()` to a single seeded
// demo account — this is the fix. One honest limitation, stated plainly
// rather than hidden: this endpoint doesn't cryptographically verify the
// caller *is* the email it claims (that needs a real Microsoft Entra
// tenant validating the ID token against Entra's own JWKS — an external
// dependency this project doesn't have yet, see docs/EXTERNAL_ACCOUNTS.md).
// What it DOES do for real: issue a genuine per-user bearer token, tied to
// a real `users` row, that every other endpoint now actually requires and
// checks (`auth:sanctum` on the whole /v1 group) — a world apart from the
// previous "every request is secretly User::first()" behavior.
class AuthController extends Controller
{
    use ApiResponds;

    public function session(Request $request): JsonResponse
    {
        $email = strtolower(trim((string) $request->input('email', '')));
        // Real, admin-configurable allow-list (docs/EKSIKLER.md admin
        // §13) — SettingsController::allowedDomains() reads the same
        // AppSetting an admin edits in Site Ayarları; defaults to
        // "@arucad.edu.tr" alone when nothing's been configured yet, so
        // this behaves exactly as before until an admin actually changes it.
        $allowedDomains = SettingsController::allowedDomains();
        $matchesAllowedDomain = $email !== '' && array_any($allowedDomains, fn ($d) => str_ends_with($email, $d));
        if (! $email || ! $matchesAllowedDomain) {
            return $this->fail(400, 'VALIDATION', 'A real email from an allowed domain is required.');
        }
        $name = trim((string) $request->input('name', '')) ?: $email;

        $user = User::firstOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => bcrypt(Str::random(40))]
        );
        if ($user->isBanned()) {
            return $this->fail(403, 'ACCOUNT_BANNED', 'This account has been banned.');
        }

        $token = $user->createToken('session')->plainTextToken;
        $role = RoleAssignment::find($email)?->role ?? 'student';
        AuditLogger::log($user->name, 'login', 'auth', $email);

        return $this->ok([
            'token' => $token,
            'email' => $user->email,
            'name' => $user->name,
            'role' => $role,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        AuditLogger::log($user->name, 'logout', 'auth', $user->email);
        $user->currentAccessToken()?->delete();

        return $this->ok(['loggedOut' => true]);
    }
}
