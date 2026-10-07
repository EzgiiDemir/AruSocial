<?php

namespace App\Http\Middleware;

use App\Services\Moderation\AccountStanding;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

// Real consequence for the points ladder in AccountEnforcementPolicy:
// once an account is suspended, every API request from it is rejected
// here — not just the one endpoint that tripped it. Applied to the whole
// /api/v1 group in routes/api.php, same as throttle:api.
class EnsureNotBanned
{
    public function handle(Request $request, Closure $next): Response
    {
        // A ban now blocks every protected endpoint for everyone, admins
        // included. There used to be a `/admin/*` exemption here, because
        // with one shared demo account a ban would have locked the only
        // person who could lift it out of the tools to lift it. Real
        // per-user auth removed that trap — whoever reviews a ban is a
        // different account that isn't banned — and the exemption had
        // become a hole instead: any banned account could still reach the
        // admin API by choosing an /admin/* path. Ban is a property of the
        // account, so it's enforced on the account, never on the URL.
        //
        // The real authenticated user, not "whichever user row came first"
        // — a ban used to lock out every account at once because this read
        // User::first(). Null only if this middleware ever runs outside
        // `auth:sanctum`, in which case there's no ban to enforce.
        $user = $request->user();
        if ($user === null) {
            return $next($request);
        }

        // Permanent ban (a human decision) and timed suspension (the
        // points ladder) are both enforced here. A posting restriction is
        // NOT: it is the lighter penalty and is enforced at the content
        // gate, so a restricted student can still read and use the app. AccountStanding clears an expired timed ban as a
        // side effect of this check, so access comes back on its own using
        // server time — never the device clock.
        if (! (new AccountStanding)->isCurrentlyBanned($user)) {
            return $next($request);
        }

        $until = $user->banned_at !== null ? null : $user->banned_until;

        return response()->json([
            'data' => null,
            'meta' => ['request_id' => 'req-'.Str::uuid()],
            'error' => [
                'code' => 'ACCOUNT_BANNED',
                'message' => $until !== null
                    ? 'Hesabın geçici olarak askıya alındı. Tekrar erişebileceğin zaman: '
                        .$until->timezone(config('app.timezone'))->format('d.m.Y H:i').'.'
                    : 'Bu hesap topluluk kurallarını ihlal nedeniyle askıya alındı.',
                'bannedUntil' => $until?->toIso8601String(),
            ],
        ], 403);
    }
}
