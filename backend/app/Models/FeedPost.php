<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FeedPost extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id', 'author_id', 'name', 'text', 'meta', 'likes',
        'image_url', 'visibility', 'post_type', 'course_tag', 'location_tag',
        'official', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'official' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function comments()
    {
        return $this->hasMany(PostComment::class, 'post_id')->orderBy('created_at');
    }
}
