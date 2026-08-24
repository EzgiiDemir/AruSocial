<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterPushTokenRequest;
use App\Http\Requests\UnregisterPushTokenRequest;
use App\Models\PushToken;
use Illuminate\Http\JsonResponse;

// Real device-token registration — see push_tokens migration's doc
// comment: genuinely stored, nothing actually delivered to them without
// a real Firebase project (docs/EXTERNAL_ACCOUNTS.md §2).
class PushTokenController extends Controller
{
    use ApiResponds;

    public function register(RegisterPushTokenRequest $request): JsonResponse
    {
        $me = $this->currentUser();
        $token = $request->input('token');
        $platform = $request->input('platform');

        // Device switched accounts: the same FCM token must belong to one user.
        PushToken::query()->where('token', $token)->where('user_id', '!=', $me->id)->delete();
        PushToken::updateOrCreate(
            ['user_id' => $me->id, 'token' => $token],
            ['platform' => $platform],
        );

        return $this->ok(['registered' => true]);
    }

    public function unregister(UnregisterPushTokenRequest $request): JsonResponse
    {
        $me = $this->currentUser();
        PushToken::where('user_id', $me->id)->where('token', $request->input('token'))->delete();

        return $this->ok(['unregistered' => true]);
    }
}
