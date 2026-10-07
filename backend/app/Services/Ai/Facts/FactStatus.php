<?php

namespace App\Services\Ai\Facts;

/**
 * Whether a requirement is FACTUALLY supported — stricter than Phase 3B's
 * evidence availability: a retrieved passage can be relevant and still not
 * state the fact (INSUFFICIENT).
 */
enum FactStatus: string
{
    /** A validated fact exists. */
    case SUPPORTED = 'SUPPORTED';
    /** No evidence at all (the requirement stayed unavailable). */
    case UNSUPPORTED = 'UNSUPPORTED';
    /** Sources disagree and the evidence policy could not decide. */
    case CONFLICTING = 'CONFLICTING';
    /** Evidence exists, but no candidate fact survived extraction and validation. */
    case INSUFFICIENT = 'INSUFFICIENT';
    /** Only stale or expired information exists. */
    case STALE = 'STALE';
    /** The task was blocked by a failed dependency. */
    case NOT_APPLICABLE = 'NOT_APPLICABLE';
}
