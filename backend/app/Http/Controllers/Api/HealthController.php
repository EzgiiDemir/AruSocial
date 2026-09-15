<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Services\Moderation\AccountEnforcement;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

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
            'moderation' => $this->moderationStatus() + [
                // Reported unconditionally. A deployment running with
                // account enforcement switched off looks completely
                // normal from the outside — content is still refused —
                // so the only way to notice it was left off after a
                // testing window is to be able to ask.
                'enforcement' => AccountEnforcement::enabled() ? 'on' : 'off',
            ],
        ]);
    }

    /**
     * Whether the visual classifier can actually answer.
     *
     * Reported here because its absence is invisible everywhere else and
     * looks exactly like a content problem to a student: with the scanner
     * down every photo upload correctly fails closed with a
     * 503, so "Stories block everything" and "the classifier process is
     * not running" produce the same symptom. One field turns a confusing
     * outage into an obvious one.
     */
    private function moderationStatus(): array
    {
        if (! (bool) config('moderation.image.enabled', false)) {
            // Not an error. An install can legitimately run without a
            // visual classifier — media is then held rather than
            // published, which the upload path already enforces.
            return ['image' => 'disabled'];
        }

        $base = rtrim((string) config('moderation.image.base_url'), '/');

        try {
            $response = Http::timeout(3)->get($base.'/health');
        } catch (\Throwable) {
            return ['image' => 'unreachable', 'url' => $base];
        }

        if ($response->status() === 503) {
            // The service is up but its weights failed to load, which is
            // a different fix from "the process is not running".
            return ['image' => 'model_not_loaded', 'url' => $base];
        }

        if (! $response->successful()) {
            return ['image' => 'unhealthy', 'url' => $base];
        }

        return [
            'image' => 'ok',
            'model' => $response->json('model'),
            'modelVersion' => $response->json('model_version'),
            'policyVersion' => config('moderation.image.policy_version'),
        ];
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
