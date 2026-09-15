<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\PolicyConsent;
use App\Services\AuditLogger;
use App\Services\Legal\PolicyDocuments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Whether this student has accepted the policy now in force.
 *
 * The app also gates on a locally stored acknowledgement so the notice can
 * be shown before anyone signs in, but that is a convenience. This is the
 * record: it names the person, the moment, the version and the language
 * they read it in, and it survives a reinstall, a new phone, and the
 * student clearing app data.
 */
class PolicyConsentController extends Controller
{
    use ApiResponds;

    /**
     * What is in force, and whether this account has accepted it.
     */
    public function show(): JsonResponse
    {
        $me = $this->currentUser();
        $version = PolicyDocuments::currentVersion();

        $accepted = PolicyConsent::query()
            ->where('user_id', $me->id)
            ->where('document', PolicyDocuments::PRIVACY)
            ->where('version', $version)
            ->first();

        return $this->ok([
            'document' => PolicyDocuments::PRIVACY,
            'version' => $version,
            'required' => $accepted === null,
            'acceptedAt' => $accepted?->accepted_at?->toIso8601String(),
            'acceptedLocale' => $accepted?->locale,
        ]);
    }

    /**
     * Records acceptance of whatever is in force right now.
     *
     * The client deliberately does not send a version. It sends "I accepted
     * what you showed me", and the server writes the version it is
     * currently serving — so a stale or hand-crafted client cannot record
     * consent to a policy that is no longer the policy.
     *
     * Idempotent: accepting twice keeps the first timestamp, because the
     * moment the person actually agreed is the one worth having.
     */
    public function store(Request $request): JsonResponse
    {
        $me = $this->currentUser();

        $locale = strtolower((string) $request->input('locale', 'tr'));
        if (! PolicyDocuments::isKnownLocale($locale)) {
            return $this->fail(
                400,
                'VALIDATION',
                'locale must be one of: '.implode(', ', PolicyDocuments::LOCALES).'.',
            );
        }

        $version = PolicyDocuments::currentVersion();

        $consent = PolicyConsent::firstOrCreate(
            [
                'user_id' => $me->id,
                'document' => PolicyDocuments::PRIVACY,
                'version' => $version,
            ],
            [
                'locale' => $locale,
                'accepted_at' => now(),
            ],
        );

        if ($consent->wasRecentlyCreated) {
            // Not `logAsCurrentUser`: this is the student's own act, not an
            // admin acting on them, and the trail should say so.
            AuditLogger::log(
                $me->email,
                'accept',
                'policy_consent',
                PolicyDocuments::PRIVACY.' '.$version,
            );
        }

        return $this->ok([
            'document' => $consent->document,
            'version' => $consent->version,
            'required' => false,
            'acceptedAt' => $consent->accepted_at?->toIso8601String(),
            'acceptedLocale' => $consent->locale,
        ]);
    }
}
