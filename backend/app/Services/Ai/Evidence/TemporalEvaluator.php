<?php

namespace App\Services\Ai\Evidence;

use App\Models\AcademicYear;
use Carbon\CarbonInterface;

/**
 * When an evidence item holds — decided only from the validity period the
 * source actually carries (valid_from / valid_until). Nothing is guessed:
 *
 *  - no validity data                 → UNKNOWN (a standing fact such as
 *    building coordinates or regular hours is normally UNKNOWN; that is
 *    not a defect, and the policy decides whether it is acceptable)
 *  - retrieved_at / published_at are NEVER read as validity: a page fetched
 *    today may describe last year, and an old page may still be true.
 *  - a dated record that has passed is EXPIRED, or HISTORICAL for fact
 *    types that remain true about the past (an event that took place).
 */
final class TemporalEvaluator
{
    /** Fact types whose past records are history, not expired claims. */
    private const HISTORICAL_TYPES = ['current_events'];

    /** @return array{status: TemporalStatus, reason: string} */
    public function evaluate(Evidence $evidence, CarbonInterface $now): array
    {
        if ($evidence->value === null) {
            return ['status' => TemporalStatus::UNKNOWN, 'reason' => 'no evidence value'];
        }
        if ($evidence->validFrom === null && $evidence->validUntil === null) {
            return ['status' => TemporalStatus::UNKNOWN, 'reason' => 'source carries no validity period'];
        }
        if ($evidence->validFrom !== null && $now->lt($evidence->validFrom)) {
            return ['status' => TemporalStatus::FUTURE, 'reason' => 'valid from '.$evidence->validFrom->toDateTimeString()];
        }
        if ($evidence->validUntil !== null && $now->gt($evidence->validUntil)) {
            return in_array($evidence->factType, self::HISTORICAL_TYPES, true)
                ? ['status' => TemporalStatus::HISTORICAL, 'reason' => 'took place before '.$evidence->validUntil->toDateTimeString()]
                : ['status' => TemporalStatus::EXPIRED, 'reason' => 'valid until '.$evidence->validUntil->toDateTimeString()];
        }

        return ['status' => TemporalStatus::CURRENT, 'reason' => 'within its validity period'];
    }

    /**
     * Whether the academic year flagged active has already ended — the
     * classic stale-configuration fault that silently dates every
     * "this year" answer. Diagnostic only; never corrected here.
     *
     * @return array{active_label: string, ends_on: string, stale: bool}|null
     */
    public function academicYear(CarbonInterface $now): ?array
    {
        $year = AcademicYear::query()->where('is_active', true)->orderByDesc('starts_on')->first();
        if ($year === null || $year->ends_on === null) {
            return null;
        }

        return [
            'active_label' => (string) $year->label,
            'ends_on' => $year->ends_on->toDateString(),
            'stale' => $now->gt($year->ends_on->copy()->endOfDay()),
        ];
    }
}
