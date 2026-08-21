<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

// Wraps every response in the {data, meta, error} envelope from
// docs/API_CONTRACT.md — the same contract the Node prototype backend used,
// so RestCampusRepository's parsing code needed zero changes to point here.
trait ApiResponds
{
    protected function ok($data, int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => $data,
            'meta' => ['request_id' => 'req-'.Str::uuid()],
            'error' => null,
        ], $status);
    }

    protected function fail(int $status, string $code, string $message): JsonResponse
    {
        return response()->json([
            'data' => null,
            'meta' => ['request_id' => 'req-'.Str::uuid()],
            'error' => ['code' => $code, 'message' => $message],
        ], $status);
    }

    protected function newId(string $prefix): string
    {
        return $prefix.'-'.Str::uuid();
    }

    // Real, per-request identity (docs/EKSIKLER.md "Gerçek JWT/session
    // authentication") — resolved from the Sanctum bearer token every /v1
    // route now requires (see routes/api.php's `auth:sanctum` middleware
    // and AuthController::session()), not a hardcoded single account.
    protected function currentUser(): User
    {
        return request()->user();
    }
}
