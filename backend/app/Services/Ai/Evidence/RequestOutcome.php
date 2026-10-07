<?php

namespace App\Services\Ai\Evidence;

/** Orchestration status of a planned question (not answer wording): decided deterministically. */
enum RequestOutcome: string
{
    case COMPLETE = 'COMPLETE';
    case PARTIAL = 'PARTIAL';
    case UNAVAILABLE = 'UNAVAILABLE';
    case FAILED = 'FAILED';
}
