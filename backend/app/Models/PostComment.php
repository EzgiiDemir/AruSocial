<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostComment extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['id', 'post_id', 'user_id', 'text', 'meta', 'created_at'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(FeedPost::class, 'post_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Presentation fields (`author`) come from the user row, not a stored
    // name. A rename updates every comment without rewriting this table.
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'author' => $this->user?->name ?? '',
            'text' => $this->text,
            'meta' => $this->meta,
        ];
    }
}
