<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatThreadPref extends Model
{
    protected $fillable = [
        'user_id',
        'peer_user_id',
        'muted_at',
        'archived_at',
        'restricted_at',
    ];

    protected function casts(): array
    {
        return [
            'muted_at' => 'datetime',
            'archived_at' => 'datetime',
            'restricted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function peer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'peer_user_id');
    }

    public function toStateArray(): array
    {
        return [
            'muted' => $this->muted_at !== null,
            'archived' => $this->archived_at !== null,
            'restricted' => $this->restricted_at !== null,
        ];
    }
}
