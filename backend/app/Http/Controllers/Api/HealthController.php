<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

// Connection diagnostics for the exact URL the Flutter app is configured
// with (API_BASE_URL, i.e. …/api/v1). Without these two routes that URL
// answers Laravel's "The route api/v1 could not be found." 404, which is
// indistinguishable from a backend that isn't running at all — the first
// thing anyone checks when the app can't reach the API.
//
// Deliberately carries no user or domain data: this is a reachability
// probe, not an unauthenticated window into anything the protected
// endpoints hold.
class HealthController extends Controller
{
    use ApiResponds;

    public function index(): JsonResponse
    {
        return $this->ok([
            'status' => 'ok',
            'service' => 'arucad-campus-api',
            'version' => 'v1',
            'database' => $this->databaseStatus(),
        ]);
    }

    // Reported as a field rather than thrown: an unreachable database is
    // the most common reason the app still fails right after the server
    // itself answers (a missing sql/database.sqlite, say), but letting it
    // fail the whole response would make this endpoint useless for
    // diagnosing everything else.
    private function databaseStatus(): string
    {
        try {
            DB::connection()->getPdo();

            return 'ok';
        } catch (\Throwable) {
            return 'unavailable';
        }
    }
}
