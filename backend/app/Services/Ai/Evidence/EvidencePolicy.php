<?php

namespace App\Services\Ai\Evidence;

use Carbon\CarbonInterface;

/**
 * Deterministic, per-fact-type rules for which evidence may count and how
 * strongly. No model is consulted, and there is deliberately no universal
 * "database beats web" order: the right authority depends on the fact.
 * Regular hours live in the campus rows, but a current official
 * announcement of a temporary change is stronger; a programme's language is
 * best stated by the extracted programme fact or the programme page, while a
 * single course syllabus is weak evidence for the whole programme.
 */
final class EvidencePolicy
{
    /**
     * Fact type → accepted authority classes, strongest first.
     *
     * @var array<string, list<string>>
     */
    public const AUTHORITY = [
        'current_opening_hours' => ['official_announcement', 'authoritative_operational', 'official_document'],
        'place_coordinates' => ['authoritative_operational'],
        'user_location' => ['request_context'],
        'routing' => ['routing_service'],
        'required_documents' => ['official_announcement', 'official_document', 'course_document'],
        'program_language' => ['structured_fact', 'official_document', 'course_document'],
        // Only the extracted programme field block: a duration is never read from prose.
        'programme_duration' => ['structured_fact'],
        'current_menu' => ['authoritative_operational', 'official_announcement'],
        'current_events' => ['authoritative_operational', 'official_announcement'],
        'food_places' => ['authoritative_operational'],
        'club_social_profile' => ['authoritative_operational', 'official_document'],
        // The official academic calendar page, parsed deterministically.
        'academic_date' => ['official_document'],
        'contact_details' => ['authoritative_operational'],
    ];

    /**
     * Fact types whose requirement asks about now: only evidence valid now
     * (CURRENT) or standing evidence without a period (UNKNOWN) may count.
     */
    private const CURRENT_TYPES = ['current_opening_hours', 'current_menu', 'current_events', 'user_location', 'routing'];

    /**
     * A document passage counts toward a fact type only when it actually
     * talks about that kind of fact — otherwise any page about the office or
     * the programme would mark the requirement satisfied without saying
     * anything about documents or language. Fixed vocabulary (tr/en/ru) per
     * fact type, not per-question tuning; recall-oriented, not claim checking.
     *
     * @var array<string, string>
     */
    private const PASSAGE_CUES = [
        'required_documents' => '/belge|evrak|dilekçe|fotokopi|fotoğraf|kimlik|pasaport|transkript|diploma|document|required|passport|certificate|документ|паспорт|справк/iu',
        'program_language' => '/eğitim dili|öğretim dili|ingilizce|türkçe|language of instruction|english|turkish|язык обучения|английск|турецк/iu',
    ];

    /** Stale sources are kept, but only behind every live source. */
    private const STALE_PENALTY = 100;

    public function __construct(private readonly TemporalEvaluator $temporal) {}

    public function assess(Evidence $evidence, CarbonInterface $now): AssessedEvidence
    {
        ['status' => $temporal, 'reason' => $temporalReason] = $this->temporal->evaluate($evidence, $now);
        $no = fn (string $why) => new AssessedEvidence($evidence, $temporal, $temporalReason, false, null, $why);

        if ($evidence->value === null) {
            return $no('no value: '.$evidence->reason);
        }
        $order = self::AUTHORITY[$evidence->factType] ?? [];
        $rank = array_search($evidence->authorityClass, $order, true);
        if ($rank === false) {
            return $no("authority {$evidence->authorityClass} is not accepted for {$evidence->factType}");
        }
        if (in_array($temporal, [TemporalStatus::EXPIRED, TemporalStatus::FUTURE], true)) {
            return $no('not valid now ('.strtolower($temporal->value).')');
        }
        if ($temporal === TemporalStatus::HISTORICAL && in_array($evidence->factType, self::CURRENT_TYPES, true)) {
            return $no('historical record for a question about now');
        }
        if ($evidence->scope === 'exception' && $temporal !== TemporalStatus::CURRENT) {
            // An exception without a known period cannot be applied to today.
            return $no('exception without a current validity period');
        }
        if ($evidence->valueType === 'document_passage' && isset(self::PASSAGE_CUES[$evidence->factType])) {
            if (! preg_match(self::PASSAGE_CUES[$evidence->factType], (string) $evidence->value)) {
                return $no($evidence->factType === 'required_documents'
                    ? 'passage does not state any documents' : 'passage does not state a '.str_replace('_', ' ', $evidence->factType));
            }
            if (($evidence->metadata['mentions_subject'] ?? null) === false) {
                return $no('passage does not concern the task subject');
            }
        }

        $stale = $evidence->status === EvidenceStatus::STALE;

        return new AssessedEvidence($evidence, $temporal, $temporalReason, true,
            $rank + ($stale ? self::STALE_PENALTY : 0),
            $evidence->authorityClass.' accepted (rank '.$rank.')'.($stale ? ', demoted: stale source' : ''));
    }
}
