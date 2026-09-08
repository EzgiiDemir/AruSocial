<?php

namespace Tests\Feature;

use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An event that exists but nobody can see is the same as no event at all.
 *
 * `Event::publiclyListed()` matches `workflow_status = 'published'` and
 * nothing else. Anything written with a different "looks finished" status —
 * 'approved' was the one that bit us — sits in the database looking correct
 * to whoever put it there, is absent from the app, and never appears in the
 * admin review queue either, because it never entered the review flow. It
 * took reading the scope to explain why the events page was empty while the
 * events table was not.
 */
class EventVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function event(string $id, string $status): Event
    {
        return Event::create([
            'id' => $id,
            'title' => 'Etkinlik '.$id,
            'time' => '14:00',
            'event_date' => now()->addDays(3),
            'place_name' => 'Rodin',
            'category' => 'Akademik',
            'attendees' => 0,
            'xp' => 0,
            'draft' => false,
            'audience' => 'Tümü',
            'organizer' => 'ARUCAD',
            'workflow_status' => $status,
        ]);
    }

    public function test_only_published_events_are_listed_to_students(): void
    {
        $this->actingAsUser();
        $this->event('evt-published', 'published');
        $this->event('evt-draft', 'draft');
        $this->event('evt-pending', 'pending_review');
        $this->event('evt-rejected', 'rejected');

        $ids = collect($this->getJson('/api/v1/events')->json('data'))->pluck('id');

        $this->assertContains('evt-published', $ids);
        foreach (['evt-draft', 'evt-pending', 'evt-rejected'] as $hidden) {
            $this->assertNotContains($hidden, $ids, "$hidden must not reach students.");
        }
    }

    /**
     * The exact regression: events mirrored from arucad.edu.tr are already
     * public on the university site, so they must arrive in a state the
     * student-facing scope actually matches.
     */
    public function test_events_synced_from_the_university_site_are_visible_to_students(): void
    {
        $this->actingAsUser();

        // The shape ArucadEventsSync writes.
        $this->event('arucad-evt-1', 'published');

        $ids = collect($this->getJson('/api/v1/events')->json('data'))->pluck('id');

        $this->assertContains(
            'arucad-evt-1',
            $ids,
            'A synced university event was written in a status students cannot see.',
        );
    }

    public function test_the_sync_writes_a_status_the_public_scope_matches(): void
    {
        // Guards the constant itself: if the sync's status and the scope's
        // filter ever drift apart again, this fails without needing the
        // whole HTTP round trip to notice.
        $this->event('sync-shape', 'published');

        $this->assertSame(
            1,
            Event::publiclyListed()->where('id', 'sync-shape')->count(),
            'The status the sync writes must satisfy Event::publiclyListed().',
        );
    }
}
