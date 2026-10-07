<?php

namespace App\Services\Ai\Planning;

/** A mention with its candidates (best first), status, candidate margin and the reason. */
final readonly class EntityResolution
{
    /** @param list<PreliminaryEntityCandidate> $candidates best first */
    public function __construct(
        public Mention $mention,
        public ResolutionStatus $status,
        public array $candidates,
        public ?float $margin,
        public string $reason,
    ) {}

    public function best(): ?PreliminaryEntityCandidate
    {
        return $this->status === ResolutionStatus::RESOLVED ? ($this->candidates[0] ?? null) : null;
    }

    public function toArray(): array
    {
        return [
            'mention' => $this->mention->toArray(),
            'status' => $this->status->value,
            'candidates' => array_map(fn (PreliminaryEntityCandidate $c) => $c->toArray(), $this->candidates),
            'candidate_margin' => $this->margin,
            'reason' => $this->reason,
        ];
    }
}
