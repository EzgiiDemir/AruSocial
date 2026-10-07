<?php

namespace App\Services\Ai\Planning;

/** Where AICAD can currently obtain one requirement; provider null = nothing can satisfy it. */
final readonly class ProviderRoute
{
    public function __construct(
        public string $requirementId,
        public string $taskId,
        public string $factType,
        /** a registered provider key, or null when nothing can satisfy it */
        public ?string $provider,
    ) {}

    public function routed(): bool
    {
        return $this->provider !== null;
    }

    public function toArray(): array
    {
        return ['requirement_id' => $this->requirementId, 'task_id' => $this->taskId, 'fact_type' => $this->factType, 'provider' => $this->provider];
    }
}
