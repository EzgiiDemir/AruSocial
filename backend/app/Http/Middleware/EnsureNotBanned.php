<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

// Real consequence for the strike system in ModerationService: once an
// account is banned, every API request from it is rejected here — not
// just the one endpoint that tripped the strike. Applied to the whole
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
        if ($user !== null && $user->banned_at !== null) {
            return response()->json([
                'data' => null,
                'meta' => ['request_id' => 'req-'.Str::uuid()],
                'error' => [
                    'code' => 'ACCOUNT_BANNED',
                    'message' => 'Bu hesap topluluk kurallarını ihlal nedeniyle askıya alındı.',
                ],
            ], 403);
        }

        return $next($request);
    }
}
