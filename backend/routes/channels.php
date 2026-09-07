<?php

use App\Models\Conversation;
use App\Models\User;
use App\Services\GranularPermissions;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('conversation.{conversationId}', function (User $user, int|string $conversationId) {
    $conversation = Conversation::query()->find($conversationId);

    return $conversation !== null && $conversation->hasParticipant((int) $user->id);
}, ['guards' => ['sanctum']]);

Broadcast::channel('user.{userId}', function (User $user, int|string $userId) {
    return (int) $user->id === (int) $userId;
}, ['guards' => ['sanctum']]);

Broadcast::channel('ops.appointments', function (User $user) {
    return GranularPermissions::allows($user, 'appointments.manage');
}, ['guards' => ['sanctum']]);
