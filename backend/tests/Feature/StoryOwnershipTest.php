<?php

namespace Tests\Feature;

use App\Models\Story;
use App\Models\MediaItem;
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

    /**
     * Regression: `stories` had no `image_url` column at all, so a photo
     * story — uploaded via /media/mine on the client to get a real hosted
     * URL — had nowhere to be stored server-side and always came back with
     * no image, even though the upload itself succeeded.
     */
    public function test_a_story_can_be_created_with_an_owned_approved_image_url(): void
    {
        [$user, $token] = $this->signInChatUser('Kullanıcı B', 'b@arucad.edu.tr');
        $media = MediaItem::create([
            'id' => 'media-story-photo', 'user_id' => $user->id,
            'file_path' => 'media/story-photo.jpg', 'file_name' => 'story-photo.jpg',
            'mime_type' => 'image/jpeg', 'size_bytes' => 1,
            'uploaded_at' => now(), 'uploaded_by' => $user->name,
            'used_in' => [], 'moderation_status' => 'approved',
        ]);

        $created = $this->withToken($token)->postJson('/api/v1/stories', [
            'imageUrl' => $media->url(),
        ])->assertOk()->json('data');

        $this->assertSame($media->url(), $created['imageUrl']);
        $this->assertDatabaseHas('stories', [
            'id' => $created['id'],
            'image_url' => $media->url(),
        ]);

        $listed = $this->withToken($token)->getJson('/api/v1/stories')->assertOk()->json('data');
        $this->assertSame(
            $media->url(),
            collect($listed)->firstWhere('id', $created['id'])['imageUrl'],
        );
    }
}
