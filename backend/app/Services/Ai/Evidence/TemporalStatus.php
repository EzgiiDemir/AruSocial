<?php

namespace App\Services\Ai\Evidence;

/** When evidence holds, from the validity data the source actually carries; UNKNOWN is never guessed away. */
enum TemporalStatus: string
{
    case CURRENT = 'CURRENT';
    case FUTURE = 'FUTURE';
    case EXPIRED = 'EXPIRED';
    case HISTORICAL = 'HISTORICAL';
    case UNKNOWN = 'UNKNOWN';
}
