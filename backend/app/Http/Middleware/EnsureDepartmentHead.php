<?php

namespace App\Http\Middleware;

use App\Models\StaffProfile;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

// Resolves *which* department a Trainer Panel request is scoped to.
// `permission:events.manageOwnDepartment` (EnsurePermission) only answers
// "is this account provisioned as a trainer at all" — this middleware
// answers "which real department, concretely," by requiring the account
// to be linked (StaffProfile.user_id) to an active staff record.
// Controllers must read the department off the request attribute this
// sets, never from a client-supplied field — that's what makes it
// impossible for a trainer to act on another department's events by
// guessing an id.
class EnsureDepartmentHead
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null) {
            throw new AuthenticationException();
        }

        $staff = StaffProfile::where('user_id', $user->id)
            ->where('active', true)
            ->first();

        if ($staff === null) {
            return response()->json([
                'data' => null,
                'meta' => ['request_id' => 'req-'.Str::uuid()],
                'error' => [
                    'code' => 'NOT_A_DEPARTMENT_HEAD',
                    'message' => 'Bu hesap aktif bir personel kaydına bağlı değil. Yöneticiniz personel e-postasını ve Trainer rolünü tanımlamalı.',
                ],
            ], 403);
        }

        $request->attributes->set('departmentHeadStaff', $staff);

        return $next($request);
    }
}
