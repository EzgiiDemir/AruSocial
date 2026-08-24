<?php

namespace App\Jobs;

use App\Services\PushNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class DeliverFcmNotification implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 120;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public int $userId,
        public string $title,
        public string $body,
        public array $data,
        public string $dedupeKey,
    ) {
        // RefreshDatabase wraps tests in a transaction that never commits
        // during the assertion phase, so afterCommit jobs would never run.
        $this->afterCommit = ! app()->runningUnitTests();
    }

    public function uniqueId(): string
    {
        return $this->dedupeKey;
    }

    public function handle(PushNotificationService $push): void
    {
        $cacheKey = 'fcm.sent.'.$this->dedupeKey;
        if (! Cache::add($cacheKey, 1, 3600)) {
            return;
        }

        try {
            $push->sendToUser($this->userId, $this->title, $this->body, $this->data);
        } catch (\Throwable $e) {
            Cache::forget($cacheKey);
            Log::warning('FCM job failed', ['error' => $e->getMessage()]);
            // Additive observability only — this job already handles its
            // own failure (log + dedupe cache clear) and doesn't rethrow,
            // so Laravel's queue retry/failed-job semantics are unchanged.
            // config/sentry.php's before_send strips the Firebase private
            // key from context before this leaves the process.
            \Sentry\captureException($e);
        }
    }
}
