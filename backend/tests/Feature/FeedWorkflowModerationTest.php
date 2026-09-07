<?php

namespace Tests\Feature;

use App\Models\FeedPost;
use App\Models\User;
use App\Events\CampusDataChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as EventFacade;
use Tests\TestCase;

class FeedWorkflowModerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_when_approval_is_required_a_new_post_is_hidden_from_others(): void
    {
        EventFacade::fake([CampusDataChanged::class]);
        config(['services.feed.require_approval' => true]);
        $author = $this->actingAsUser();

        $this->postJson('/api/v1/feed', ['text' => 'inceleme bekleyen gönderi'])
            ->assertOk()
            ->assertJsonPath('data.workflowStatus', 'pending_review');

        $this->assertDatabaseHas('feed_posts', [
            'author_id' => $author->id,
            'workflow_status' => 'pending_review',
        ]);
        EventFacade::assertDispatched(CampusDataChanged::class, fn (CampusDataChanged $change) =>
            $change->resources === ['moderation'] && $change->action === 'submitted_for_review'
        );

        $feed = $this->getJson('/api/v1/feed')->json('data');
        $this->assertTrue(collect($feed)->contains(fn ($p) => $p['text'] === 'inceleme bekleyen gönderi'));

        $other = User::create([
            'name' => 'Other',
            'email' => 'other@arucad.edu.tr',
            'password' => bcrypt('x'),
        ]);
        $this->actingAsUser($other);
        $othersFeed = $this->getJson('/api/v1/feed')->json('data');
        $this->assertFalse(collect($othersFeed)->contains(fn ($p) => $p['text'] === 'inceleme bekleyen gönderi'));
    }

    public function test_admin_can_publish_a_pending_post(): void
    {
        config(['services.feed.require_approval' => true]);
        $author = $this->actingAsUser();
        $this->postJson('/api/v1/feed', ['text' => 'bekleyen'])->assertOk();
        $post = FeedPost::where('author_id', $author->id)->first();

        $this->actingAsRole('superAdmin');
        EventFacade::fake([CampusDataChanged::class]);
        $this->getJson('/api/v1/admin/moderation/posts')
            ->assertOk()
            ->assertJsonPath('data.0.id', $post->id);

        $this->postJson("/api/v1/admin/moderation/posts/{$post->id}/approve")->assertOk();
        EventFacade::assertDispatched(CampusDataChanged::class, fn (CampusDataChanged $change) =>
            $change->resources === ['feed', 'moderation']
                && $change->action === 'published'
                && $change->id === $post->id
        );

        $other = User::create([
            'name' => 'Other',
            'email' => 'other2@arucad.edu.tr',
            'password' => bcrypt('x'),
        ]);
        $this->actingAsUser($other);
        $feed = $this->getJson('/api/v1/feed')->json('data');
        $this->assertTrue(collect($feed)->contains(fn ($p) => $p['id'] === $post->id));
    }
}
