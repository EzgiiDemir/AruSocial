<?php

namespace Tests\Feature;

use App\Models\Story;
use App\Models\StoryView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * A story that is merely hidden after 24 hours has not expired — it is
 * still on disk, still readable by anything that queries the table, and
 * still there in a backup a year later. These pin the difference.
 */
class StoryExpiryTest extends TestCase
{
    use RefreshDatabase;

    private function story(string $id, int $authorId, string $createdAt): Story
    {
        return Story::create([
            'id' => $id,
            'author_id' => $authorId,
            'author_name' => 'Test',
            'text' => 'merhaba',
            'visibility' => 'friends',
            'created_at' => $createdAt,
        ]);
    }

    public function test_stories_past_24_hours_are_deleted(): void
    {
        $me = $this->actingAsUser();

        $this->story('s-old', (int) $me->id, now()->subHours(25)->toDateTimeString());
        $this->story('s-fresh', (int) $me->id, now()->subHours(23)->toDateTimeString());

        Artisan::call('stories:purge-expired');

        $this->assertNull(Story::find('s-old'), 'An expired story must actually be removed.');
        $this->assertNotNull(Story::find('s-fresh'), 'A story inside its 24 hours must survive.');
    }

    public function test_deleting_a_story_takes_its_view_records_with_it(): void
    {
        $me = $this->actingAsUser();
        $this->story('s-old', (int) $me->id, now()->subHours(30)->toDateTimeString());

        StoryView::create([
            'story_id' => 's-old',
            'viewer_user_id' => $me->id,
            'viewed_at' => now()->subHours(29),
        ]);

        Artisan::call('stories:purge-expired');

        $this->assertSame(0, StoryView::where('story_id', 's-old')->count(),
            'Who watched an expired story must not outlive the story.');
    }

    public function test_dry_run_reports_without_deleting(): void
    {
        $me = $this->actingAsUser();
        $this->story('s-old', (int) $me->id, now()->subHours(48)->toDateTimeString());

        Artisan::call('stories:purge-expired', ['--dry-run' => true]);

        $this->assertNotNull(Story::find('s-old'));
    }
}
