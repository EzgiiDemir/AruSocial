<?php

namespace App\Services\Ai\Planning;

/**
 * One candidate entity for a mention. `resolverScore` is EntityResolver's fixed
 * per-method score (name 1.0, alias 0.9, typo 0.6, halved when ambiguous): an
 * ordering signal, NOT a calibrated probability, and never presented as one.
 */
final readonly class PreliminaryEntityCandidate
{
    public function __construct(
        public string $entityType,
        public string $entityId,
        public string $label,
        public float $resolverScore,
        /** exact_name | alias | fuzzy */
        public string $matchType,
        /** high | medium | low */
        public string $matchStrength,
    ) {}

    public function ref(): string
    {
        return $this->entityType.':'.$this->entityId;
    }

    public function toArray(): array
    {
        return [
            'entity_type' => $this->entityType, 'entity_id' => $this->entityId, 'label' => $this->label,
            'resolver_score' => $this->resolverScore, 'match_type' => $this->matchType, 'match_strength' => $this->matchStrength,
        ];
    }
}
