<?php

namespace Tests\Feature;

use App\Models\FeedPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsInChatUsers;
use Tests\TestCase;

class FeedOwnershipTest extends TestCase
{
    use RefreshDatabase, SignsInChatUsers;

    public function test_a_created_post_is_owned_by_the_signed_in_user(): void
    {
        [$a, $tokenA] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');

        $data = $this->withToken($tokenA)->postJson('/api/v1/feed', [
            'text' => 'merhaba kampüs',
            'name' => 'Sahte İsim',
            'authorId' => '999',
        ])->assertOk()->json('data');

        $this->assertSame((string) $a->id, $data['authorId']);
        $this->assertSame('Kullanıcı A', $data['name']);
        $this->assertIsString($data['authorId']);
        $this->assertDatabaseHas('feed_posts', [
            'id' => $data['id'],
            'author_id' => $a->id,
            'name' => 'Kullanıcı A',
            'text' => 'merhaba kampüs',
        ]);
        $this->assertSame($a->id, (int) FeedPost::value('author_id'));
    }
}
