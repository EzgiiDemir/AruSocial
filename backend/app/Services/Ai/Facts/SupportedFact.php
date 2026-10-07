<?php

namespace App\Services\Ai\Facts;

/**
 * A fact the system — not the model — has established for one requirement:
 * a validated value with its evidence ids, provenance, temporal status and
 * the rule that validated it. Generation may state only these.
 *
 * There is deliberately no confidence probability: support is a yes/no
 * decision with its reason, not a score.
 */
final readonly class SupportedFact
{
    /**
     * @param  array{type: string, id: string, name: string}|null  $subject
     * @param  list<string>  $evidenceIds
     * @param  list<array{evidence_id: string, source_type: ?string, source_id: ?string, url: ?string, title: ?string, authority_class: ?string, page: mixed}>  $provenance
     * @param  array{method: string, reason: string}  $validation
     * @param  list<string>  $anchors  folded strings that identify a claim about this fact in an answer
     */
    public function __construct(
        public string $id,
        public string $taskId,
        public string $requirementId,
        public string $candidateId,
        public ?array $subject,
        public string $factType,
        public mixed $value,
        public string $normalizedValue,
        public string $valueType,
        public array $evidenceIds,
        public array $provenance,
        public ?string $temporalStatus,
        public ?string $authorityClass,
        public array $validation,
        public ?string $span = null,
        public array $anchors = [],
        /** a value the model must never see verbatim (the student's coordinates) */
        public bool $redacted = false,
        /** conditions the value holds under, e.g. is_open_now: evaluated_at, timezone */
        public array $qualifiers = [],
    ) {}

    /** The value as the model may read it. */
    public function display(): string
    {
        if ($this->redacted) {
            return 'paylaşıldı';
        }
        if ($this->valueType === 'location') {
            // A place is named, never given as raw coordinates.
            return ($this->subject['name'] ?? 'yer').' (konumu kayıtlı)';
        }
        if ($this->factType === 'current_opening_hours' && is_array($this->value)) {
            return (string) ($this->value['text'] ?? '');
        }
        if ($this->factType === 'academic_date' && is_array($this->value)) {
            $fmt = function (string $d): string {
                $tr = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
                [$y, $m, $day] = array_map('intval', explode('-', $d));

                return $day.' '.$tr[$m - 1].' '.$y.' ('.$d.')';
            };
            $v = $this->value;
            $term = ['fall' => 'Güz dönemi', 'spring' => 'Bahar dönemi', 'summer' => 'Yaz okulu'][$v['term'] ?? ''] ?? null;

            $terms = ['fall' => 'Güz dönemi', 'spring' => 'Bahar dönemi', 'summer' => 'Yaz okulu'];
            $recent = isset($v['recent']['start'])
                ? ' (önceki: '.($terms[$v['recent']['term'] ?? ''] ?? 'dönem').' '.$fmt((string) $v['recent']['start']).' tarihinde başladı)' : '';

            return ($term !== null ? $term.' — ' : '').trim((string) ($v['label'] ?? '')).': '.$fmt((string) $v['start']).($v['end'] !== $v['start'] ? ' – '.$fmt((string) $v['end']) : '')
                .$recent.' ['.($v['academic_year'] ?? '').']';
        }
        if ($this->factType === 'current_menu') {
            return implode(', ', array_map('strval', (array) ($this->value['items'] ?? [])));
        }
        if ($this->factType === 'current_events') {
            return trim(implode(' ', array_filter([$this->value['title'] ?? null, $this->value['date'] ?? null, $this->value['time'] ?? null, $this->value['place'] ?? null])));
        }
        if (is_bool($this->value)) {
            return $this->value ? 'evet' : 'hayır';
        }

        return is_array($this->value)
            ? implode(', ', array_map(fn ($v) => is_scalar($v) ? (string) $v : (string) json_encode($v, JSON_UNESCAPED_UNICODE), $this->value))
            : (string) $this->value;
    }

    public function toArray(): array
    {
        return array_filter([
            'fact_id' => $this->id, 'task_id' => $this->taskId, 'requirement_id' => $this->requirementId,
            'candidate_id' => $this->candidateId, 'subject' => $this->subject, 'fact_type' => $this->factType,
            'value' => $this->redacted ? '[redacted]' : $this->value,
            'normalized_value' => $this->redacted ? '[redacted]' : $this->normalizedValue,
            'value_type' => $this->valueType, 'support_status' => FactStatus::SUPPORTED->value,
            'evidence_ids' => $this->evidenceIds, 'provenance' => $this->provenance,
            'temporal_status' => $this->temporalStatus, 'authority_class' => $this->authorityClass,
            'validation' => $this->validation, 'span' => $this->span, 'qualifiers' => $this->qualifiers,
        ], fn ($v) => $v !== null && $v !== []);
    }
}
