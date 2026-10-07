<?php

namespace App\Jobs;

use App\Models\AiEvaluationRun;
use App\Services\Ai\Evaluation\EvaluationRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs an evaluation started from the admin panel. Queued: a retrieval
 * suite takes about a minute and a full-answer suite several, far longer
 * than a web request should.
 */
class RunAiEvaluationJob implements ShouldQueue
{
    use Queueable;

    // One run, one result per case: a failed run is reported, never retried.
    public int $tries = 1;

    public int $timeout = 3600;

    public function __construct(public readonly int $runId) {}

    public function handle(EvaluationRunner $runner): void
    {
        $run = AiEvaluationRun::query()->find($this->runId);
        if ($run !== null && $run->status === AiEvaluationRun::STATUS_QUEUED) {
            $runner->execute($run);
        }
    }
}
