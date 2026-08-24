<?php

namespace Tests\Feature;

use App\Models\Story;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsInChatUsers;
use Tests\TestCase;

class StoryOwnershipTest extends TestCase
{
    use RefreshDatabase, SignsInChatUsers;

    public function test_a_created_story_is_owned_by_the_signed_in_user(): void
    {
        [$a, $tokenA] = $this->signInChatUser('Kullanıcı A', 'a@arucad.edu.tr');

        $data = $this->withToken($tokenA)->postJson('/api/v1/stories', [
            'text' => 'stüdyoda',
            'authorName' => 'Sahte İsim',
            'authorId' => '999',
        ])->assertOk()->json('data');

        $this->assertSame((string) $a->id, $data['authorId']);
        $this->assertSame('Kullanıcı A', $data['authorName']);
        $this->assertIsString($data['authorId']);
        $this->assertDatabaseHas('stories', [
            'id' => $data['id'],
            'author_id' => $a->id,
            'author_name' => 'Kullanıcı A',
            'text' => 'stüdyoda',
        ]);
        $this->assertSame($a->id, (int) Story::value('author_id'));
    }
}
