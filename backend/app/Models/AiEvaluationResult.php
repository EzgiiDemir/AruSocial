<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The outcome of one case within one run, with a redacted diagnostic snapshot. */
class AiEvaluationResult extends Model
{
    public const STATUS_PASSED = 'passed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'run_id', 'case_id', 'case_name', 'question', 'mode', 'status', 'failure_stage',
        'failed_assertions', 'snapshot', 'trace_id', 'duration_ms',
    ];

    protected function casts(): array
    {
        return ['failed_assertions' => 'array', 'snapshot' => 'array'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AiEvaluationRun::class, 'run_id');
    }

    public function evaluationCase(): BelongsTo
    {
        return $this->belongsTo(AiEvaluationCase::class, 'case_id');
    }
}
