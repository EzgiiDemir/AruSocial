<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\ToggleSavedPostRequest;
use App\Models\SavedPost;
use Illuminate\Http\JsonResponse;

class SavedPostController extends Controller
{
    use ApiResponds;

    public function index(): JsonResponse
    {
        $me = $this->currentUser();
        $ids = SavedPost::where('user_id', $me->id)->pluck('post_id');

        return $this->ok($ids);
    }

    public function toggle(ToggleSavedPostRequest $request): JsonResponse
    {
        $me = $this->currentUser();
        $postId = $request->input('postId');

        $existing = SavedPost::where('user_id', $me->id)->where('post_id', $postId)->first();
        if ($existing) {
            $existing->delete();

            return $this->ok(['saved' => false]);
        }
        SavedPost::create(['user_id' => $me->id, 'post_id' => $postId, 'created_at' => now()]);

        return $this->ok(['saved' => true]);
    }
}
