<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

// Real consequence for the strike system in ModerationService (banned_at):
// once an account is banned, every API request from it is rejected here —
// not just the one endpoint that tripped the strike. Also enforces a real,
// separate account-status flag: deactivated_at, a manual/reversible admin
// action (docs/EKSIKLER.md admin §9, "kullanıcıyı pasif hale getirebilmeli")
// with no strike/moderation meaning attached — distinct reason, distinct
// error code, same blocking effect. Applied to the whole /api/v1 group in
// routes/api.php, same as throttle:api. Runs after auth:sanctum, so
// $request->user() is the real, authenticated caller.
class EnsureNotBanned
{
    public function handle(Request $request, Closure $next): Response
    {
        // Admin routes stay reachable even for a banned/deactivated
        // account — a role that can manage the platform (or reverse the
        // status) shouldn't be able to lock itself out this way. Real
        // enforcement of *who* may reach /admin/* at all is EnsurePermission's
        // job, layered on top of this.
        if ($request->is('api/v1/admin/*')) {
            return $next($request);
        }

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
        if ($user !== null && $user->deactivated_at !== null) {
            return response()->json([
                'data' => null,
                'meta' => ['request_id' => 'req-'.Str::uuid()],
                'error' => [
                    'code' => 'ACCOUNT_DEACTIVATED',
                    'message' => 'Bu hesap bir yönetici tarafından pasif hale getirildi.',
                ],
            ], 403);
        }

        return $next($request);
    }
}
