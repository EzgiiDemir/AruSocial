<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A confirmed policy breach attributed to an account.
 *
 * Nothing here is written by a model. A classifier produces evidence;
 * only a human decision (or an explicitly configured auto-confirm rule)
 * produces a violation. That separation is what keeps one wrong
 * prediction from costing somebody their account.
 *
 * `points` expire. A mistake two years ago should not compound with one
 * today, and a ladder with no decay eventually bans everyone who stays.
 */
class UserViolation extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id', 'user_id', 'moderation_case_id', 'category', 'severity',
        'confirmed', 'points', 'action_taken', 'decided_by',
        'idempotency_key', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'confirmed' => 'boolean',
            'points' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Confirmed, not yet expired — the only rows enforcement may read. */
    public function scopeCounting($query)
    {
        return $query->where('confirmed', true)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }
}
