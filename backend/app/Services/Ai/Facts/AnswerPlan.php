<?php

namespace App\Services\Ai\Facts;

/**
 * The deterministic contract given to generation: per task, what the answer
 * may and must cover — not prose. A task that is not supported tells the
 * model to say so instead of answering.
 */
final readonly class AnswerPlan
{
    public const SUPPORTED = 'supported';

    public const PARTIAL = 'partial';

    public const INSUFFICIENT = 'insufficient';

    public const CONFLICTING = 'conflicting';

    public const STALE = 'stale';

    public const CONTEXT_MISSING = 'context_missing';

    public const UNAVAILABLE = 'unavailable';

    public const NOT_APPLICABLE = 'not_applicable';

    /**
     * What an absence means. A missing row or an unresolved name is
     * NOT_FOUND_IN_CURRENT_DATA — "we could not find it", never "it does not
     * exist". AUTHORITATIVELY_NOT_OFFERED needs a source that states the
     * absence (a complete official list); AICAD has none today, so it is
     * never assigned and absolute negatives are rejected by ClaimVerifier.
     */
    public const NOT_FOUND_IN_CURRENT_DATA = 'not_found_in_current_data';

    public const AUTHORITATIVELY_NOT_OFFERED = 'authoritatively_not_offered';

    /**
     * @param  list<array{task_id: string, task_type: string, status: string, fact_ids: list<string>, reasons: list<string>, conflict_values: list<string>, absence?: string}>  $tasks
     * @param  string  $outcome  COMPLETE | PARTIAL | UNAVAILABLE — what the answer can deliver
     */
    public function __construct(
        public array $tasks,
        public string $outcome,
    ) {}

    /** @return array{task_id: string, task_type: string, status: string, fact_ids: list<string>, reasons: list<string>, conflict_values: list<string>}|null */
    public function task(string $taskId): ?array
    {
        foreach ($this->tasks as $task) {
            if ($task['task_id'] === $taskId) {
                return $task;
            }
        }

        return null;
    }

    public function toArray(): array
    {
        return ['outcome' => $this->outcome, 'tasks' => $this->tasks];
    }
}
