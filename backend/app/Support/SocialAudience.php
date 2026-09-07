<?php

namespace App\Support;

use App\Models\SocialFollow;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class SocialAudience
{
    public static function visibility(?string $raw, string $fallback = 'everyone'): string
    {
        return match ($raw) {
            'onlyMe' => 'onlyMe',
            'friends' => 'friends',
            'everyone' => 'everyone',
            default => $fallback,
        };
    }

    /** @return list<int> */
    public static function friendIds(User $me): array
    {
        $followingIds = SocialFollow::query()
            ->accepted()
            ->where('follower_user_id', $me->id)
            ->pluck('followed_user_id');

        return SocialFollow::query()
            ->accepted()
            ->where('followed_user_id', $me->id)
            ->whereIn('follower_user_id', $followingIds)
            ->pluck('follower_user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public static function constrainFeed(Builder $query, User $me): void
    {
        $friendIds = self::friendIds($me);
        $query->where(function ($q) use ($me, $friendIds) {
            $q->where('author_id', $me->id)
                ->orWhere('official', true)
                ->orWhere('visibility', 'everyone')
                ->orWhereNull('visibility')
                ->orWhere(function ($inner) use ($friendIds) {
                    $inner->where('visibility', 'friends')
                        ->whereIn('author_id', $friendIds);
                });
        });
    }

    public static function constrainStories(Builder $query, User $me): void
    {
        $friendIds = self::friendIds($me);
        $query->where(function ($q) use ($me, $friendIds) {
            $q->where('author_id', $me->id)
                ->orWhere('visibility', 'everyone')
                ->orWhereNull('visibility')
                ->orWhere(function ($inner) use ($friendIds) {
                    $inner->where('visibility', 'friends')
                        ->whereIn('author_id', $friendIds);
                });
        });
    }

    public static function mayView(?string $visibility, int $authorId, User $viewer, bool $official = false): bool
    {
        if ($official || (int) $authorId === (int) $viewer->id) {
            return true;
        }
        if ($visibility === 'onlyMe') {
            return false;
        }
        if ($visibility === 'friends') {
            return in_array((int) $authorId, self::friendIds($viewer), true);
        }

        return true;
    }
}
