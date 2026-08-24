<?php

namespace App\Services;

use App\Models\PushToken;
use Illuminate\Support\Facades\Log;

class PushNotificationService
{
    public function __construct(private FcmClient $fcm) {}

    /**
     * Fan-out to every stored token for $userId. Permanent FCM token
     * errors drop the row; transient errors are logged and swallowed so
     * inbox history is never rolled back.
     *
     * @param  array<string, mixed>  $data
     */
    public function sendToUser(int $userId, string $title, string $body, array $data = []): void
    {
        $tokens = PushToken::query()->where('user_id', $userId)->get();
        foreach ($tokens as $row) {
            try {
                $result = $this->fcm->send($row->token, $title, $body, $data);
            } catch (\Throwable $e) {
                Log::warning('FCM send threw', ['user_id' => $userId, 'error' => $e->getMessage()]);

                continue;
            }
            if ($result->invalidToken) {
                $row->delete();
            }
        }
    }
}
