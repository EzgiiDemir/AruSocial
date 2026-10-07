<?php

namespace App\Services\Ai\Planning;

/** How decisively a mention resolved to a canonical entity. */
enum ResolutionStatus: string
{
    case RESOLVED = 'RESOLVED';
    case AMBIGUOUS = 'AMBIGUOUS';
    case UNRESOLVED = 'UNRESOLVED';
}
