<?php

namespace App\Services\Ai\Planning;

use InvalidArgumentException;

/**
 * A validated DAG of tasks. Construction enforces every invariant: unique ids,
 * no self-dependency, no unknown dependency, no cycle.
 */
final readonly class TaskPlan
{
    /** @param list<Task> $tasks */
    public function __construct(public array $tasks)
    {
        $ids = array_map(fn (Task $t) => $t->id, $tasks);
        if (count($ids) !== count(array_unique($ids))) {
            throw new InvalidArgumentException('Task ids must be unique.');
        }
        foreach ($tasks as $task) {
            foreach ($task->dependsOn as $dependency) {
                if ($dependency === $task->id) {
                    throw new InvalidArgumentException("Task {$task->id} cannot depend on itself.");
                }
                if (! in_array($dependency, $ids, true)) {
                    throw new InvalidArgumentException("Task {$task->id} depends on unknown task {$dependency}.");
                }
            }
        }
        $this->topologicalOrder(); // throws on a cycle
    }

    public function task(string $id): ?Task
    {
        foreach ($this->tasks as $task) {
            if ($task->id === $id) {
                return $task;
            }
        }

        return null;
    }

    /**
     * Tasks in waves: every task in a wave depends only on earlier waves,
     * so a wave's tasks are independent of each other.
     *
     * @return list<list<string>>
     */
    public function topologicalOrder(): array
    {
        $remaining = [];
        foreach ($this->tasks as $task) {
            $remaining[$task->id] = $task->dependsOn;
        }
        $done = [];
        $waves = [];
        while ($remaining !== []) {
            $wave = array_keys(array_filter($remaining, fn (array $deps) => array_diff($deps, $done) === []));
            if ($wave === []) {
                throw new InvalidArgumentException('Task dependencies contain a cycle: '.implode(', ', array_keys($remaining)));
            }
            $waves[] = $wave;
            $done = array_merge($done, $wave);
            foreach ($wave as $id) {
                unset($remaining[$id]);
            }
        }

        return $waves;
    }

    public function toArray(): array
    {
        return array_map(fn (Task $t) => $t->toArray(), $this->tasks);
    }
}
