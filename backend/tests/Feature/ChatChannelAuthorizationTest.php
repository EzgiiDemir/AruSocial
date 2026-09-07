<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ConversationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsInChatUsers;
use Tests\TestCase;

class ChatChannelAuthorizationTest extends TestCase
{
    use RefreshDatabase, SignsInChatUsers;

    protected function setUp(): void
    {
        parent::setUp();
        // phpunit uses the null broadcaster, whose auth() is a no-op (always
        // 200). Channel rules must be checked with the Pusher/Reverb signer.
        // Do not send() while this driver is active — that would HTTP to Reverb.
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'arucad-local-key',
            'broadcasting.connections.reverb.secret' => 'arucad-local-secret',
            'broadcasting.connections.reverb.app_id' => '1001',
            'broadcasting.connections.reverb.options.host' => '127.0.0.1',
            'broadcasting.connections.reverb.options.port' => 8080,
            'broadcasting.connections.reverb.options.scheme' => 'http',
            'broadcasting.connections.reverb.options.useTLS' => false,
        ]);
        // Channel callbacks were bound to the null driver at boot. Re-register
        // them on the Reverb/Pusher signer this test actually authenticates with.
        require base_path('routes/channels.php');
    }

    public function test_participant_may_subscribe_and_outsiders_may_not(): void
    {
        [$a, $tokenA] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');
        [$b, $tokenB] = $this->signInChatUser('Kullanıcı B', 'b@arucad.edu.tr');
        [, $tokenC] = $this->signInChatUser('Kullanıcı C', 'c@arucad.edu.tr');

        $conversation = app(ConversationService::class)->findOrCreatePair($a, $b);

        $this->withToken($tokenA)
            ->postJson('/broadcasting/auth', $this->authBody('private-conversation.'.$conversation->id))
            ->assertOk();

        $this->withToken($tokenB)
            ->postJson('/broadcasting/auth', $this->authBody('private-conversation.'.$conversation->id))
            ->assertOk();

        $this->withToken($tokenC)
            ->postJson('/broadcasting/auth', $this->authBody('private-conversation.'.$conversation->id))
            ->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->withoutToken()
            ->postJson('/broadcasting/auth', $this->authBody('private-conversation.'.$conversation->id))
            ->assertUnauthorized();
    }

    public function test_user_channel_is_only_the_owner(): void
    {
        [$a, $tokenA] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');
        /** @var User $a */
        [$b, $tokenB] = $this->signInChatUser('Kullanıcı B', 'b@arucad.edu.tr');

        $this->withToken($tokenA)
            ->postJson('/broadcasting/auth', $this->authBody('private-user.'.$a->id))
            ->assertOk();

        $this->withToken($tokenB)
            ->postJson('/broadcasting/auth', $this->authBody('private-user.'.$a->id))
            ->assertForbidden();
    }

    public function test_ops_appointments_channel_requires_manage_permission(): void
    {
        $this->actingAsUser();
        $this->postJson('/broadcasting/auth', $this->authBody('private-ops.appointments'))
            ->assertForbidden();

        $this->actingAsRole();
        $this->postJson('/broadcasting/auth', $this->authBody('private-ops.appointments'))
            ->assertOk();
    }

    /** @return array{socket_id: string, channel_name: string} */
    private function authBody(string $channel): array
    {
        return [
            'socket_id' => '1234.5678',
            'channel_name' => $channel,
        ];
    }
}
