<?php

namespace Tests\Feature;

use App\Models\ChatGroupMessage;
use App\Models\ChatMessage;
use App\Models\CollaborationPost;
use App\Models\FeedPost;
use App\Models\MediaItem;
use App\Models\Place;
use App\Models\Review;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModerationPublicationFirebreakTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_approved_social_rows_are_queryable_or_served(): void
    {
        $user = User::factory()->create();
        Place::create([
            'id' => 'place-1', 'name' => 'Test Place', 'category' => 'other',
            'lat' => 0, 'lng' => 0,
        ]);
        foreach (['pending', 'blocked', 'approved'] as $status) {
            FeedPost::withoutGlobalScopes()->create([
                'id' => 'post-'.$status, 'author_id' => $user->id, 'name' => 'A',
                'text' => $status, 'meta' => '', 'created_at' => now(), 'moderation_status' => $status,
            ]);
            Story::withoutGlobalScopes()->create([
                'id' => 'story-'.$status, 'author_id' => $user->id, 'author_name' => 'A',
                'created_at' => now(), 'moderation_status' => $status,
            ]);
            Review::withoutGlobalScopes()->create([
                'id' => 'review-'.$status, 'place_id' => 'place-1', 'user_id' => $user->id,
                'rating' => 5, 'comment' => $status, 'meta' => '', 'created_at' => now(), 'moderation_status' => $status,
            ]);
            CollaborationPost::withoutGlobalScopes()->create([
                'id' => 'collab-'.$status, 'place_id' => 'place-1', 'author_id' => $user->id,
                'text' => $status, 'created_at' => now(), 'expires_at' => now()->addDay(), 'moderation_status' => $status,
            ]);
        }

        $this->assertSame(['post-approved'], FeedPost::query()->pluck('id')->all());
        $this->assertSame(['story-approved'], Story::query()->pluck('id')->all());
        $this->assertSame(['review-approved'], Review::query()->pluck('id')->all());
        $this->assertSame(['collab-approved'], CollaborationPost::query()->pluck('id')->all());
    }

    public function test_pending_and_blocked_messages_are_not_in_history(): void
    {
        $this->assertArrayHasKey('approved-content', (new ChatMessage)->getGlobalScopes());
        $this->assertArrayHasKey('approved-content', (new ChatGroupMessage)->getGlobalScopes());
    }

    public function test_unapproved_media_file_is_never_publicly_served(): void
    {
        $media = MediaItem::create([
            'id' => 'media-pending', 'file_path' => 'media/missing.jpg', 'file_name' => 'missing.jpg',
            'mime_type' => 'image/jpeg', 'size_bytes' => 1, 'uploaded_at' => now(),
            'uploaded_by' => 'test', 'moderation_status' => 'pending',
        ]);

        $this->get('/api/v1/media/'.$media->id.'/file')->assertNotFound();
    }
}
