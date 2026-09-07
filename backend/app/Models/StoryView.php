<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoryView extends Model
{
    public $timestamps = false;

    protected $fillable = ['story_id', 'viewer_user_id', 'viewed_at'];

    protected function casts(): array
    {
        return [
            'viewer_user_id' => 'integer',
            'viewed_at' => 'datetime',
        ];
    }

    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class, 'story_id');
    }

    public function viewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'viewer_user_id');
    }
}
