<?php

namespace App\Models;

use App\Jobs\DeliverFcmNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

// Named to match the real in-app notification inbox concept — not to be
// confused with Illuminate\Notifications\Notification (Laravel's queued
// mail/SMS/push base class, a different namespace entirely). Always
// import this one explicitly as App\Models\Notification.
class Notification extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = ['id', 'user_id', 'actor_user_id', 'kind', 'title', 'body', 'data', 'read_at', 'created_at'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    // Recipient is who should see it; actor is who did the thing. Names
    // belong in title/body as presentation, never as the owner. A person
    // never receives a row for their own action.
    /**
     * @param  array<string, mixed>  $data  Extra FCM data (conversationId, messageId, peer, …)
     */
    public static function notify(User $recipient, User $actor, string $kind, string $title, string $body, array $data = []): ?self
    {
        if ((int) $recipient->id === (int) $actor->id) {
            return null;
        }

        $row = self::create([
            'id' => 'notif-'.(string) Str::uuid(),
            'user_id' => $recipient->id,
            'actor_user_id' => $actor->id,
            'kind' => $kind,
            'title' => $title,
            'body' => $body,
            'data' => $data === [] ? null : $data,
            'created_at' => now(),
        ]);

        DeliverFcmNotification::dispatch(
            userId: (int) $row->user_id,
            title: $title,
            body: $body,
            data: array_merge([
                'type' => $kind,
                'notificationId' => $row->id,
            ], $data),
            dedupeKey: $row->id,
        );

        return $row;
    }
}
