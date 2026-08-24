<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\User;
use InvalidArgumentException;

class ConversationService
{
    public function findPair(User $a, User $b): ?Conversation
    {
        if ($a->id === $b->id) {
            return null;
        }

        return Conversation::query()
            ->where('pair_key', Conversation::pairKey($a->id, $b->id))
            ->first();
    }

    public function findOrCreatePair(User $a, User $b): Conversation
    {
        if ($a->id === $b->id) {
            throw new InvalidArgumentException('A conversation needs two distinct people.');
        }

        $conversation = Conversation::query()->firstOrCreate(
            ['pair_key' => Conversation::pairKey($a->id, $b->id)],
        );
        $conversation->participants()->syncWithoutDetaching([$a->id, $b->id]);

        return $conversation;
    }

    public function resolvePeer(string $peer): User|string
    {
        if ($peer === '') {
            return 'missing';
        }
        if (ctype_digit($peer)) {
            return User::query()->whereKey($peer)->first() ?? 'missing';
        }
        $matches = User::query()->where('name', $peer)->get();
        if ($matches->count() === 1) {
            return $matches->first();
        }
        if ($matches->isEmpty()) {
            return 'missing';
        }

        return 'ambiguous';
    }
}
