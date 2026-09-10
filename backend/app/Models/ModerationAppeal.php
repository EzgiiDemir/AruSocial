<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A student contesting a decision.
 *
 * There is deliberately no field for an automatic outcome. Re-running the
 * same classifier and calling the result a review is not a review — it is
 * the same answer with more steps, and it is the thing an appeals process
 * exists to prevent.
 */
class ModerationAppeal extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    public const STATUS_OPEN = 'open';
    public const STATUS_REVIEWING = 'reviewing';
    public const STATUS_UPHELD = 'upheld';
    public const STATUS_OVERTURNED = 'overturned';

    protected $fillable = [
        'id', 'moderation_case_id', 'user_id', 'original_decision',
        'reason', 'status', 'reviewed_by', 'review_note', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function moderationCase(): BelongsTo
    {
        return $this->belongsTo(ModerationCase::class, 'moderation_case_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
