<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Story extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'id', 'author_id', 'author_name', 'text', 'image_url', 'media_mime_type', 'background_color_value',
        'style_json', 'visibility', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'author_id' => 'integer',
            'created_at' => 'datetime',
            'style_json' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function views(): HasMany
    {
        return $this->hasMany(StoryView::class, 'story_id');
    }
}
