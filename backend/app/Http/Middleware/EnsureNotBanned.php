<?php

namespace App\Http\Middleware;

use App\Models\User;
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
        // Admin routes stay reachable even for a banned account — with
        // only one real demo user in this prototype (no separate admin
        // identity), blocking /admin/* too would make a ban permanently
        // un-reversible via the API itself. A real deployment with real
        // per-user auth would gate this on role instead of path.
        if ($request->is('api/v1/admin/*')) {
            return $next($request);
        }

        // Same single-demo-account pattern as ApiResponds::currentUser() —
        // no real per-request auth yet, so this checks the one seeded user.
        $user = User::first();
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
