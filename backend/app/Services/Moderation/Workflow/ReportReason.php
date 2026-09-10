<?php

namespace App\Services\Moderation\Workflow;

/**
 * Why someone reported something.
 *
 * A closed set rather than free text. The old reports table stored only a
 * prose `reason`, which meant nothing could be counted, prioritised or
 * compared — and a queue you cannot sort is a queue nobody works.
 *
 * Severity here is the *claim* being made, not a finding. It decides how
 * fast a human looks, never what happens to the account: a report is a
 * signal, and treating one as proof is how brigading becomes a moderation
 * tool.
 */
enum ReportReason: string
{
    case Harassment = 'harassment';
    case Bullying = 'bullying';
    case Hate = 'hate';
    case Threat = 'threat';
    case SexualContent = 'sexual_content';
    case Violence = 'violence';
    case Spam = 'spam';
    case Scam = 'scam';
    case PersonalInformation = 'personal_information';
    case Impersonation = 'impersonation';
    case SelfHarm = 'self_harm';
    case Drugs = 'drugs';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $r) => $r->value, self::cases());
    }

    /**
     * Queue priority, 0 highest.
     *
     * Ordered by how bad it is to be slow. A credible threat or a
     * safeguarding concern loses value the moment it waits; spam is
     * annoying at any speed. Self-harm sits at the top not because it is
     * a violation — it usually is not — but because a person may need
     * help today.
     */
    public function priority(): int
    {
        return match ($this) {
            self::Threat, self::SelfHarm => 0,
            self::Hate, self::SexualContent, self::PersonalInformation => 10,
            self::Violence, self::Harassment, self::Bullying => 20,
            self::Impersonation, self::Scam => 30,
            self::Drugs => 40,
            self::Spam, self::Other => 60,
        };
    }

    /**
     * Severity band if the report turns out to be well-founded. Read only
     * by AccountEnforcementPolicy, and only after a human confirms it.
     */
    public function severityIfConfirmed(): string
    {
        return match ($this) {
            self::Threat => 'critical',
            self::Hate, self::PersonalInformation, self::SexualContent => 'severe',
            self::Harassment, self::Bullying, self::Violence,
            self::Impersonation, self::Scam, self::Drugs => 'serious',
            // Self-harm is a support route, not a punishment ladder. It is
            // deliberately the lowest band so that a confirmed report
            // cannot, on its own, penalise someone in crisis.
            self::SelfHarm, self::Spam, self::Other => 'minor',
        };
    }

    /**
     * Whether reaching this reason should hold the content while a human
     * looks. Reserved for claims where being wrong for an hour is much
     * worse than hiding a post that turns out to be fine.
     */
    public function holdsContentPendingReview(): bool
    {
        return in_array($this, [self::Threat, self::PersonalInformation, self::SexualContent], true);
    }
}
