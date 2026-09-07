<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateSessionRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

// Real per-request identity. Until now every request silently resolved to
// User::first(), so two people using the same backend were literally the
// same account (docs/AUDIT_GERCEK_URUN.md §8). This issues a real Sanctum
// token that the rest of the API authenticates against.
//
// Microsoft Entra is still the intended production identity provider; the
// client-side scaffold for it (EntraAuthProvider) is untouched. This is the
// server-side login the prototype needs in the meantime, and it is
// deliberately the only unauthenticated write endpoint in the API.
class AuthController extends Controller
{
    use ApiResponds;

    public function session(CreateSessionRequest $request): JsonResponse
    {
        $email = (string) $request->input('email');
        $password = (string) $request->input('password');

        if (! $this->emailDomainAllowed($email)) {
            $domains = $this->allowedEmailDomains();
            $list = $domains === [] ? (string) config('auth.allowed_email_domain') : implode(', ', $domains);

            return $this->fail(403, 'DOMAIN_NOT_ALLOWED',
                "Yalnızca $list uzantılı hesaplar giriş yapabilir.");
        }

        $user = User::where('email', $email)->first();

        if ($user === null) {
            // A typed campus email is not proof of identity. Public deployments
            // provision accounts through verified SSO or an administrator.
            if (! app()->environment(['local', 'testing'])) {
                return $this->fail(401, 'INVALID_CREDENTIALS',
                    'E-posta veya şifre hatalı. Kurumsal giriş kullanın veya yöneticinizle iletişime geçin.');
            }
            // First sign-in registers the account with the password given,
            // and that is precisely what makes every *later* sign-in a real
            // check. There's no student directory to pre-provision accounts
            // from yet, so the alternative would be accepting any password
            // forever — which is the behaviour this milestone exists to end.
            $user = User::create([
                'name' => $this->nameFromEmail($email),
                'email' => $email,
                'password' => $password,
                'role' => 'student',
            ]);
        } elseif (! Hash::check($password, (string) $user->password)) {
            return $this->fail(401, 'INVALID_CREDENTIALS', 'E-posta veya şifre hatalı.');
        }

        // EnsureNotBanned guards every authenticated route, but this one is
        // public by necessity — without the check here a banned account
        // would still be handed a working token.
        if ($user->isBanned()) {
            return $this->fail(403, 'ACCOUNT_BANNED',
                'Bu hesap topluluk kurallarını ihlal nedeniyle askıya alındı.');
        }

        // One token per sign-in, so signing out on one device revokes only
        // that device's token instead of every session the account has.
        $token = $user->createToken('arucad-app')->plainTextToken;

        return $this->ok(['user' => $user->toApiArray(), 'token' => $token]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->ok(['signedOut' => true]);
    }

    // A first-time account has no display name anywhere yet; the local part
    // of the address is the only real information available, and the user
    // can still be renamed later.
    private function nameFromEmail(string $email): string
    {
        $local = Str::before($email, '@');

        return Str::of($local)->replace(['.', '_', '-'], ' ')->title()->toString();
    }

    private function emailDomainAllowed(string $email): bool
    {
        $needle = strtolower($email);
        foreach ($this->allowedEmailDomains() as $domain) {
            if (str_ends_with($needle, strtolower($domain))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function allowedEmailDomains(): array
    {
        $domains = config('auth.allowed_email_domains');
        if (is_array($domains) && $domains !== []) {
            return array_values(array_filter(array_map(
                static fn ($d): string => trim((string) $d),
                $domains,
            ), static fn (string $d): bool => $d !== ''));
        }

        $single = trim((string) config('auth.allowed_email_domain', '@arucad.edu.tr'));

        return $single !== '' ? [$single] : [];
    }
}
