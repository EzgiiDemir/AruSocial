<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One unit of human moderation work.
 *
 * Automatic verdicts, user reports and appeals all converge onto a single
 * case per piece of content, so a moderator sees one item instead of the
 * same photo arriving three times from three sources — and so the count
 * of independent reporters is a real number rather than a row count.
 */
class ModerationCase extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    public const STATUS_OPEN = 'open';

    public const STATUS_REVIEWING = 'reviewing';

    public const STATUS_RESOLVED = 'resolved';

    public const SOURCE_AUTOMATIC = 'automatic';

    public const SOURCE_USER_REPORT = 'user_report';

    public const SOURCE_APPEAL = 'appeal';

    public const SOURCE_SYSTEM = 'system';

    protected $fillable = [
        'id', 'content_type', 'content_id', 'user_id', 'source', 'priority',
        'status', 'decision', 'recommendation', 'moderation_event_id',
        'report_count', 'assigned_moderator_id', 'resolution_note', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'report_count' => 'integer',
            'resolved_at' => 'datetime',
        ];
    }

    public function reports(): HasMany
    {
        return $this->hasMany(ModerationReport::class, 'moderation_case_id');
    }

    public function violations(): HasMany
    {
        return $this->hasMany(UserViolation::class, 'moderation_case_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isOpen(): bool
    {
        return $this->status !== self::STATUS_RESOLVED;
    }
}
