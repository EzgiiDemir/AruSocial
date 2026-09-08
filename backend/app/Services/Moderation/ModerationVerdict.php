<?php

namespace App\Services\Moderation;

/**
 * The outcome of running one piece of user text through TextPolicyEngine.
 *
 * A verdict is deliberately richer than the old boolean block/allow: the
 * same insult is a warning when it is aimed at a comment, a removal when it
 * is aimed at a person, and a moderator queue item when the wording is
 * ambiguous (sarcasm, banter, an unnamed target). Callers pick the action
 * from `decision`; `severity`, `labels` and `matches` exist so an audit row
 * can explain *why* afterwards.
 */
final class ModerationVerdict
{
    public const ALLOW = 'allow';

    public const WARN = 'warn';

    public const REVIEW = 'review';

    public const REMOVE = 'remove';

    public const REMOVE_ESCALATE = 'remove_escalate';

    /**
     * @param  list<string>  $labels  Policy categories (HAR, PROF, THR, …).
     * @param  list<string>  $matches  Matched terms/rules, for the audit log.
     */
    public function __construct(
        public readonly string $decision,
        public readonly string $severity,
        public readonly array $labels,
        public readonly bool $targeted,
        public readonly string $context,
        public readonly array $matches = [],
    ) {}

    public static function allow(string $context = 'clean', string $severity = 'S0', array $labels = [], array $matches = []): self
    {
        return new self(self::ALLOW, $severity, $labels, false, $context, $matches);
    }

    /** Content must not reach the timeline. */
    public function blocksPublication(): bool
    {
        return in_array($this->decision, [self::REMOVE, self::REMOVE_ESCALATE], true);
    }

    /** Publishable, but a moderator should look at it. */
    public function needsReview(): bool
    {
        return $this->decision === self::REVIEW;
    }

    /** Publishable, but the author is told the wording is borderline. */
    public function isWarning(): bool
    {
        return $this->decision === self::WARN;
    }

    /** S4-class: a strike alone is not enough, a human must see it now. */
    public function isEscalation(): bool
    {
        return $this->decision === self::REMOVE_ESCALATE;
    }

    /** 0-4, for thresholds and ordering a review queue by risk. */
    public function severityScore(): int
    {
        return (int) ltrim($this->severity, 'S');
    }

    public function primaryLabel(): ?string
    {
        return $this->labels[0] ?? null;
    }

    /** Compact one-line description for AuditLogger. */
    public function auditSummary(): string
    {
        return sprintf(
            '%s/%s [%s]%s %s',
            $this->decision,
            $this->severity,
            implode(',', $this->labels) ?: '-',
            $this->targeted ? ' targeted' : '',
            $this->context.($this->matches ? ': '.implode(', ', array_slice($this->matches, 0, 4)) : ''),
        );
    }
}
