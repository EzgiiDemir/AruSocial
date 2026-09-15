<?php

namespace Tests\Feature;

use App\Events\CampusDataChanged;
use App\Models\User;
use App\Services\Moderation\ContentModerator;
use App\Services\Moderation\ModerationOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event as EventFacade;
use Tests\TestCase;

class FeedWorkflowModerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_blanket_approval_flag_cannot_send_a_clean_post_to_review(): void
    {
        EventFacade::fake([CampusDataChanged::class]);
        config(['services.feed.require_approval' => true]);
        $author = $this->actingAsUser();

        $this->postJson('/api/v1/feed', ['text' => 'inceleme bekleyen gönderi'])
            ->assertOk()
            ->assertJsonPath('data.workflowStatus', 'published');

        $this->assertDatabaseHas('feed_posts', [
            'author_id' => $author->id,
            'workflow_status' => 'published',
        ]);
        EventFacade::assertDispatched(CampusDataChanged::class, fn (CampusDataChanged $change) => $change->resources === ['feed'] && $change->action === 'created'
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
        $this->assertTrue(collect($othersFeed)->contains(fn ($p) => $p['text'] === 'inceleme bekleyen gönderi'));
    }

    public function test_review_outcome_is_not_persisted_or_visible(): void
    {
        $this->mock(ContentModerator::class, function ($mock) {
            $mock->shouldReceive('check')
                ->once()
                ->andReturn(ModerationOutcome::allowedWithReview());
        });
        $author = $this->actingAsUser();
        $this->postJson('/api/v1/feed', ['text' => 'bekleyen'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'MODERATION_PENDING');

        $this->assertDatabaseMissing('feed_posts', ['author_id' => $author->id, 'text' => 'bekleyen']);
    }
}
