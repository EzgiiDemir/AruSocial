<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected $fillable = ['pair_key'];

    public static function pairKey(int $a, int $b): string
    {
        return min($a, $b).':'.max($a, $b);
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'conversation_participants')
            ->withTimestamps();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'conversation_id');
    }

    public function hasParticipant(int $userId): bool
    {
        return $this->participants()->where('users.id', $userId)->exists();
    }

    public function otherParticipant(int $userId): ?User
    {
        return $this->participants->firstWhere('id', '!=', $userId);
    }
}
