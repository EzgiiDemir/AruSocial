<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\MediaPublicUrl;
use Illuminate\Http\JsonResponse;

class LeaderboardController extends Controller
{
    use ApiResponds;

    public function index(): JsonResponse
    {
        $me = $this->currentUser();
        $top = User::orderByDesc('xp')->orderBy('id')->limit(100)->get();
        $rows = $top->map(fn ($u) => [
            'name' => $u->name,
            'xp' => $u->xp,
            'avatarUrl' => MediaPublicUrl::rewrite($u->avatar_url),
            'isMe' => $u->id === $me->id,
        ]);
        if (! $top->contains(fn ($u) => $u->id === $me->id)) {
            $rows = $rows->concat([[
                'name' => $me->name,
                'xp' => $me->xp,
                'avatarUrl' => MediaPublicUrl::rewrite($me->avatar_url),
                'isMe' => true,
            ]]);
        }

        return $this->ok($rows->values());
    }
}
