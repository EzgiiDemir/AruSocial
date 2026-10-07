<?php

namespace App\Services\Ai\Facts;

/**
 * A value an extractor proposes for one requirement — NOT yet a fact.
 * Separates "retrieval found a passage" from "the passage states the fact":
 * only a candidate that FactValidator accepts becomes a SupportedFact.
 */
final readonly class CandidateFact
{
    /**
     * @param  array{type: string, id: string, name: string}|null  $subject
     * @param  list<string>  $evidenceIds  exact Phase 3B evidence ids it came from
     */
    public function __construct(
        public string $id,
        public string $taskId,
        public string $requirementId,
        public string $factType,
        public ?array $subject,
        public mixed $value,
        /** enum | string | number | date | datetime | boolean | location | list | structured */
        public string $valueType,
        public array $evidenceIds,
        /** structured | derived:<rule> | pattern:<extractor> */
        public string $method,
        /** the class that produced it (no model is used today) */
        public string $extractor,
        /** the minimal source span that states it, for document-backed candidates */
        public ?string $span = null,
        /** conditions the value holds under, e.g. is_open_now: evaluated_at, timezone */
        public array $qualifiers = [],
    ) {}

    public function toArray(): array
    {
        return array_filter([
            'candidate_id' => $this->id, 'task_id' => $this->taskId, 'requirement_id' => $this->requirementId,
            'fact_type' => $this->factType, 'subject' => $this->subject, 'value' => $this->value, 'value_type' => $this->valueType,
            'evidence_ids' => $this->evidenceIds, 'method' => $this->method, 'extractor' => $this->extractor, 'span' => $this->span,
            'qualifiers' => $this->qualifiers,
        ], fn ($v) => $v !== null && $v !== []);
    }
}
