<?php

namespace Tests\Concerns;

use App\Models\User;

trait SignsInChatUsers
{
    /** @return array{0: User, 1: string} */
    protected function signInChatUser(string $name, string $email): array
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => 'GizliSifre1']);
        $token = $this->postJson('/api/v1/auth/session', [
            'email' => $email, 'password' => 'GizliSifre1',
        ])->assertOk()->json('data.token');

        return [$user, $token];
    }

    protected function chatMessagesUrl(User $peer): string
    {
        return '/api/v1/chat/'.rawurlencode($peer->name).'/messages';
    }
}
