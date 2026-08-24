<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsInChatUsers;
use Tests\TestCase;

class MessageOwnershipTest extends TestCase
{
    use RefreshDatabase, SignsInChatUsers;

    public function test_there_is_no_edit_or_delete_route_for_someone_elses_message(): void
    {
        [, $tokenA] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');
        [$b, $tokenB] = $this->signInChatUser('Kullanıcı B', 'b@arucad.edu.tr');

        $id = $this->withToken($tokenA)
            ->postJson("/api/v1/chat/{$b->name}/messages", ['text' => 'dokunma'])
            ->assertOk()
            ->json('data.id');

        $this->withToken($tokenB)->patchJson("/api/v1/chat/{$id}", ['text' => 'hack'])->assertNotFound();
        $this->withToken($tokenB)->putJson("/api/v1/chat/{$id}", ['text' => 'hack'])->assertNotFound();
        $this->withToken($tokenB)->deleteJson("/api/v1/chat/{$id}")->assertNotFound();
        $this->withToken($tokenB)
            ->patchJson("/api/v1/chat/{$b->name}/messages/{$id}", ['text' => 'hack'])
            ->assertNotFound();
    }
}
