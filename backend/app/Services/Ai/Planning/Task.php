<?php

namespace App\Services\Ai\Planning;

/**
 * What the user wants accomplished. Knows nothing about databases or services.
 * Its id (t1, t2…) is assigned once by the planner and carried unchanged by
 * every later object, so evidence and claims can later be traced to it.
 */
final readonly class Task
{
    /**
     * @param  list<string>  $dependsOn  task ids
     * @param  list<string>  $mentions  surfaces this task is about (entity or reference)
     */
    public function __construct(
        public string $id,
        public string $type,
        public array $dependsOn = [],
        public array $mentions = [],
    ) {}

    public function toArray(): array
    {
        return ['id' => $this->id, 'type' => $this->type, 'depends_on' => $this->dependsOn, 'entity_mentions' => $this->mentions];
    }
}
