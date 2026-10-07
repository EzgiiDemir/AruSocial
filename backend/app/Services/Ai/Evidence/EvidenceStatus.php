<?php

namespace App\Services\Ai\Evidence;

/** Whether a requirement produced evidence — always observable, even when nothing exists. */
enum EvidenceStatus: string
{
    case AVAILABLE = 'AVAILABLE';
    case UNAVAILABLE = 'UNAVAILABLE';
    case NOT_APPLICABLE = 'NOT_APPLICABLE';
    case STALE = 'STALE';
    case ERROR = 'ERROR';
}
