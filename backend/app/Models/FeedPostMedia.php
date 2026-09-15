<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * One picture in a post's carousel.
 *
 * A post with no rows here is a single-image post described entirely by
 * `feed_posts.image_url`, which is how every post written before carousels
 * existed still works.
 */
class FeedPostMedia extends Model
{
    protected $table = 'feed_post_media';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * How many pictures one post may carry. Ten is the product limit; it is
     * enforced again in the request rules so a client cannot talk past it.
     */
    public const MAX_ITEMS = 10;

    protected $fillable = [
        'id', 'post_id', 'media_url', 'media_type', 'sort_order',
        'width', 'height', 'aspect_ratio', 'style_json', 'alt_text',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'aspect_ratio' => 'float',
            'style_json' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $media) {
            $media->id ??= (string) Str::uuid();
        });
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(FeedPost::class, 'post_id');
    }
}
