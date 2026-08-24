<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeedPost extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'id', 'author_id', 'name', 'text', 'meta',
        'image_url', 'visibility', 'post_type', 'course_tag', 'location_tag',
        'official', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'author_id' => 'integer',
            'official' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
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
