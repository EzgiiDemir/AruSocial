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

    // No real identity provider is wired up yet (needs a real Microsoft
    // Entra tenant only ARUCAD can create — see docs/GERCEK_PROJEYE_GECIS.md).
    // Every request is treated as the single seeded demo account, honestly,
    // rather than pretending to validate a bearer token nothing can check.
    protected function currentUser(): User
    {
        return User::firstOrFail();
    }
}
