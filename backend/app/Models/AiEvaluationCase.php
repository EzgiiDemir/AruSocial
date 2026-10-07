<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One AICAD evaluation case: a question, its context, and the structured
 * assertions that matter for it. A test, not training data — nothing here
 * ever reaches the prompt or the router.
 */
class AiEvaluationCase extends Model
{
    public const MODE_RETRIEVAL = 'retrieval';

    public const MODE_FULL = 'full';

    /** Failures in cases with this tag are reported but do not fail CI. */
    public const TAG_MODEL_SENSITIVE = 'model_sensitive';

    protected $fillable = [
        'name', 'question', 'locale', 'mode', 'active', 'tags', 'previous_turns',
        'context_user_email', 'assertions', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'tags' => 'array',
            'previous_turns' => 'array',
            'assertions' => 'array',
        ];
    }

    public function results(): HasMany
    {
        return $this->hasMany(AiEvaluationResult::class, 'case_id');
    }

    public function latestResult(): HasOne
    {
        return $this->hasOne(AiEvaluationResult::class, 'case_id')->latestOfMany();
    }

    public function isModelSensitive(): bool
    {
        return in_array(self::TAG_MODEL_SENSITIVE, (array) $this->tags, true);
    }
}
