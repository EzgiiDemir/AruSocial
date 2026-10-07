<?php

namespace App\Services\Ai\Evidence;

/** Whether the evidence for one requirement agrees, and if not, whether a rule decided it. */
enum ConflictStatus: string
{
    case NO_CONFLICT = 'NO_CONFLICT';
    case RESOLVED = 'RESOLVED';
    case UNRESOLVED_CONFLICT = 'UNRESOLVED_CONFLICT';
}
