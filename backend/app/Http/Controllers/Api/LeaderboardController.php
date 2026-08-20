<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class LeaderboardController extends Controller
{
    use ApiResponds;

    public function index(): JsonResponse
    {
        $me = $this->currentUser();
        $rows = User::orderByDesc('xp')->get()->map(fn ($u) => [
            'name' => $u->name,
            'xp' => $u->xp,
            'isMe' => $u->id === $me->id,
        ]);

        return $this->ok($rows);
    }
}
