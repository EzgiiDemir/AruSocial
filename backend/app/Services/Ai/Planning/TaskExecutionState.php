<?php

namespace App\Services\Ai\Planning;

/** The outcome of one task, keyed by the planner's task id. */
final readonly class TaskExecutionState
{
    /** @param array<string, mixed> $output small, structured, no personal data */
    public function __construct(
        public string $taskId,
        public string $taskType,
        public TaskState $state,
        public string $reason = '',
        public array $output = [],
        public int $wave = 0,
    ) {}

    public function toArray(): array
    {
        return ['task_id' => $this->taskId, 'type' => $this->taskType, 'state' => $this->state->value,
            'reason' => $this->reason, 'output' => $this->output, 'wave' => $this->wave];
    }
}
