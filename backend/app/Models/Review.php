<?php

namespace App\Models;

use App\Models\Concerns\HasPublicModerationScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    use HasPublicModerationScope;

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['id', 'place_id', 'user_id', 'rating', 'comment', 'meta', 'created_at', 'moderation_status'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function place(): BelongsTo
    {
        return $this->belongsTo(Place::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Presentation fields (`author`) come from the user row, not a stored
    // name. A rename updates every review without rewriting this table.
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'placeId' => $this->place_id,
            'author' => $this->user?->name ?? '',
            'rating' => (int) $this->rating,
            'comment' => $this->comment,
            'meta' => $this->meta,
        ];
    }
}
