<?php

namespace App\Services\Ai\Planning;

/** Whether a question is planned, and why. Decided by rules, never by a model. */
final readonly class PlanningDecision
{
    public const FAST_PATH = 'fast_path';

    public const PLANNED = 'planned';

    public const DISABLED = 'disabled';

    public function __construct(public string $mode, public string $reason, public ?string $handler = null) {}

    public function planned(): bool
    {
        return $this->mode === self::PLANNED;
    }

    public function toArray(): array
    {
        return array_filter(['mode' => $this->mode, 'handler' => $this->handler, 'reason' => $this->reason], fn ($v) => $v !== null);
    }
}
