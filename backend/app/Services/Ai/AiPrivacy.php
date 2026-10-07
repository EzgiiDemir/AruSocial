<?php

namespace App\Services\Ai;

/**
 * Whether a particular request may be answered by a provider outside ARUCAD.
 *
 * The local model is primary so that a student's question — and the
 * PersonalContext block that may be attached to it: department, year, their
 * own appointments, their club memberships — stays on campus hardware. The
 * failure mode this class exists to prevent is the quiet one: the local
 * server goes down at 2am, the fallback chain does its job, and every
 * question for the next six hours is posted to a third-party API with the
 * asker's own data in it, with nothing in the product saying so.
 *
 * So "external is unavailable" is treated exactly like "no provider is
 * available": the request degrades to the knowledge-only answer, which is
 * grounded, marked as source-based, and stays in-house.
 *
 * Two switches, both defaulting to the private choice, because they are two
 * different decisions:
 *
 *   AI_ALLOW_EXTERNAL_PROVIDERS            may we call out at all?
 *   AI_ALLOW_EXTERNAL_WITH_PERSONAL_DATA   may a prompt carrying one named
 *                                          student's own data go with it?
 *
 * Neither is inferred from anything else. A deployment that wants Groq back
 * says so in one variable.
 */
final class AiPrivacy
{
    private function __construct(
        /** The prompt carries a PersonalContext block for a named student. */
        public readonly bool $carriesPersonalData,
    ) {}

    /** A question with no personal block — general campus information. */
    public static function public(): self
    {
        return new self(false);
    }

    /** A question whose prompt includes this student's own data. */
    public static function personal(): self
    {
        return new self(true);
    }

    public static function for(bool $carriesPersonalData): self
    {
        return $carriesPersonalData ? self::personal() : self::public();
    }

    /** May [$provider] be called for this request? */
    public function allows(AiProvider $provider): bool
    {
        if ($provider->isSelfHosted()) {
            return true;
        }

        if (! (bool) config('ai.privacy.allow_external', false)) {
            return false;
        }

        return ! $this->carriesPersonalData
            || (bool) config('ai.privacy.allow_external_with_personal_data', false);
    }

    /**
     * Why an external provider was skipped, for telemetry and the admin
     * panel. Null when nothing was blocked.
     */
    public function refusalReason(): ?string
    {
        if (! (bool) config('ai.privacy.allow_external', false)) {
            return 'external_providers_disabled';
        }

        if ($this->carriesPersonalData
            && ! (bool) config('ai.privacy.allow_external_with_personal_data', false)) {
            return 'personal_data_stays_local';
        }

        return null;
    }
}
