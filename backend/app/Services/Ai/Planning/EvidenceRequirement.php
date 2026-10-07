<?php

namespace App\Services\Ai\Planning;

/** What must be known to complete a task (still no provider). */
final readonly class EvidenceRequirement
{
    public function __construct(
        public string $id,
        public string $taskId,
        public string $factType,
        /** "entity:service:student-affairs", "mention:öğrenci işleri", "user", or null */
        public ?string $subjectRef,
        /** current | static */
        public string $temporalScope,
        /** authoritative | official | contextual */
        public string $authority,
        public bool $required = true,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id, 'task_id' => $this->taskId, 'fact_type' => $this->factType, 'subject_ref' => $this->subjectRef,
            'temporal_scope' => $this->temporalScope, 'authority_requirement' => $this->authority, 'required' => $this->required,
        ];
    }
}
