<?php

namespace App\Models;

use App\Models\Concerns\HasPublicModerationScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeedPost extends Model
{
    use HasPublicModerationScope;

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id', 'author_id', 'name', 'text', 'meta',
        'image_url', 'media_mime_type', 'style_json', 'alt_text',
        'visibility', 'post_type', 'course_tag', 'location_tag',
        'official', 'workflow_status', 'review_note', 'moderation_status',
        'is_pinned', 'pinned_at', 'pinned_by', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'author_id' => 'integer',
            'official' => 'boolean',
            'is_pinned' => 'boolean',
            'pinned_at' => 'datetime',
            'created_at' => 'datetime',
            'style_json' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * The carousel, in the order the author arranged it.
     *
     * Empty for every post written before carousels and for any post with
     * one picture, which is still described by `image_url` alone.
     */
    public function media(): HasMany
    {
        return $this->hasMany(FeedPostMedia::class, 'post_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(PostComment::class, 'post_id')->orderBy('created_at')->orderBy('id');
    }

    // The like count is this relation's size — there is no counter column
    // to keep in step with it. Prefer withCount('likes') when reading a
    // list, so the feed doesn't run one query per post.
    public function likes(): HasMany
    {
        return $this->hasMany(PostLike::class, 'post_id');
    }

    public function likedBy(User $user): bool
    {
        return $this->likes()->where('user_id', $user->id)->exists();
    }
}
