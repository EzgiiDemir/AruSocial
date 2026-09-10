<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One automated moderation decision. Written for every submission that is
 * rejected, warned or held for review — and for allowed content only when
 * the provider actually flagged something, so the table stays a record of
 * decisions rather than a log of every keystroke on campus.
 */
class ModerationEvent extends Model
{
    public const ACTION_ALLOWED = 'allowed';

    public const ACTION_WARNED = 'warned';

    public const ACTION_REJECTED = 'rejected';

    public const ACTION_REVIEW = 'review';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id', 'user_id', 'content_type', 'source_feature', 'content_id',
        'action', 'flagged', 'categories', 'category_scores', 'decided_by',
        'strike_number', 'penalty', 'banned_until',
        'moderation_provider', 'moderation_model', 'excerpt', 'excerpt_purge_after',
        'submission_hash',
        // Which model and which thresholds produced this verdict. Without
        // both, a decision made under old settings cannot be explained.
        'model_version', 'policy_version', 'latency_ms', 'moderation_case_id',
    ];

    protected function casts(): array
    {
        return [
            'flagged' => 'boolean',
            'categories' => 'array',
            'category_scores' => 'array',
            'banned_until' => 'datetime',
            'excerpt_purge_after' => 'datetime',
            'strike_number' => 'integer',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scopeViolations($query)
    {
        return $query->whereIn('action', [self::ACTION_REJECTED, self::ACTION_WARNED]);
    }
}
