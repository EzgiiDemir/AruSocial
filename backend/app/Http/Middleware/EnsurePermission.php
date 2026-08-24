<?php

namespace App\Http\Middleware;

use App\Services\GranularPermissions;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

// Route-level authorization. Until now every /admin/* route trusted any
// request that reached it, so "hidden in the Flutter UI" was the only thing
// standing between a student's token and the admin API — which a plain curl
// walks straight past.
//
// Authentication and authorization answer different questions and get
// different answers here: no token at all is a 401 (who are you?), a valid
// token without the permission is a 403 (I know who you are, and no).
// Collapsing them would tell an attacker that a wrong token might work.
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        // Normally unreachable: every route using this also runs
        // `auth:sanctum` first. Kept so that adding this middleware to a
        // route someone forgot to authenticate fails closed, as a 401,
        // instead of dereferencing null.
        if ($user === null) {
            throw new AuthenticationException();
        }

        if (! GranularPermissions::allows($user, $permission)) {
            return response()->json([
                'data' => null,
                'meta' => ['request_id' => 'req-'.Str::uuid()],
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'Bu işlem için yetkin yok.',
                ],
            ], 403);
        }

        return $next($request);
    }
}
