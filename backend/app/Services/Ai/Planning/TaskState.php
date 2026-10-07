<?php

namespace App\Services\Ai\Planning;

/** Lifecycle of one task in the plan DAG. */
enum TaskState: string
{
    case PENDING = 'PENDING';
    case READY = 'READY';
    case RUNNING = 'RUNNING';
    case COMPLETED = 'COMPLETED';
    case FAILED = 'FAILED';
    case BLOCKED = 'BLOCKED';
    case SKIPPED = 'SKIPPED';
}
