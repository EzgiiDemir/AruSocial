<?php

namespace App\Models;

use App\Models\Concerns\HasPublicModerationScope;
use Illuminate\Database\Eloquent\Model;

class CollaborationPost extends Model
{
    use HasPublicModerationScope;

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['id', 'place_id', 'author_id', 'text', 'created_at', 'expires_at', 'moderation_status'];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function place()
    {
        return $this->belongsTo(Place::class);
    }

    public function author()
    {
        return $this->belongsTo(User::class);
    }
}
