<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One execution of a selection of evaluation cases, with its environment fingerprints and metrics. */
class AiEvaluationRun extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_FINISHED = 'finished';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'status', 'mode', 'scope', 'initiated_by', 'started_at', 'finished_at', 'duration_ms',
        'git_commit', 'environment', 'local_model', 'embedding_model', 'retrieval_fingerprint',
        'prompt_fingerprint', 'passed', 'failed', 'skipped', 'metrics', 'error',
    ];

    protected function casts(): array
    {
        return [
            'scope' => 'array',
            'metrics' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function results(): HasMany
    {
        return $this->hasMany(AiEvaluationResult::class, 'run_id');
    }

    public function label(): string
    {
        return '#'.$this->id.' '.$this->mode.' '.$this->created_at?->format('Y-m-d H:i');
    }
}
