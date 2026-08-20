<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\PushToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Real device-token registration — see push_tokens migration's doc
// comment: genuinely stored, nothing actually delivered to them without
// a real Firebase project (docs/EXTERNAL_ACCOUNTS.md §2).
class PushTokenController extends Controller
{
    use ApiResponds;

    public function register(Request $request): JsonResponse
    {
        $me = $this->currentUser();
        $token = $request->input('token');
        $platform = $request->input('platform');
        if (! $token || ! in_array($platform, ['android', 'ios', 'web'], true)) {
            return $this->fail(400, 'VALIDATION', 'token and a valid platform are required.');
        }

        PushToken::updateOrCreate(
            ['user_id' => $me->id, 'token' => $token],
            ['platform' => $platform, 'created_at' => now()]
        );

        return $this->ok(['registered' => true]);
    }

    public function unregister(Request $request): JsonResponse
    {
        $me = $this->currentUser();
        PushToken::where('user_id', $me->id)->where('token', $request->input('token'))->delete();

        return $this->ok(['unregistered' => true]);
    }
}
