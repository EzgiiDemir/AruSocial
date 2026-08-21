<?php

namespace Tests\Feature;

use App\Models\Place;
use App\Services\RealtimePublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RealtimeApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_poll_returns_a_cursor_without_replaying_history(): void
    {
        $this->actingAsUser();
        RealtimePublisher::emit('post.created', 'all', 'post', 'p1');

        $response = $this->getJson('/api/v1/realtime/events');

        $response->assertOk();
        $this->assertSame([], $response->json('data.events'));
        $this->assertGreaterThan(0, $response->json('data.cursor'));
    }

    public function test_subsequent_poll_returns_new_events_for_this_account(): void
    {
        $this->actingAsUser();
        $cursor = $this->getJson('/api/v1/realtime/events')->json('data.cursor');

        RealtimePublisher::emit('checkin.created', 'all', 'checkin', 'c1');
        RealtimePublisher::toUser('999', 'message.created', 'message', 'm1');

        $response = $this->getJson('/api/v1/realtime/events?after='.$cursor);

        $response->assertOk();
        $types = collect($response->json('data.events'))->pluck('type');
        $this->assertTrue($types->contains('checkin.created'));
        $this->assertFalse($types->contains('message.created'));
    }

    public function test_admin_audience_is_hidden_from_students(): void
    {
        $this->actingAsUser();
        $cursor = $this->getJson('/api/v1/realtime/events')->json('data.cursor');
        RealtimePublisher::emit('activity.submitted', 'admin', 'event', 'e1');

        $asStudent = $this->getJson('/api/v1/realtime/events?after='.$cursor);
        $asStudent->assertOk();
        $this->assertCount(0, $asStudent->json('data.events'));

        $this->actingAsAdmin('Other Admin', 'other-admin@arucad.edu.tr');
        $asAdmin = $this->getJson('/api/v1/realtime/events?after='.$cursor);
        $asAdmin->assertOk();
        $this->assertEquals('activity.submitted', $asAdmin->json('data.events.0.type'));
    }

    public function test_a_real_checkin_emits_checkin_created(): void
    {
        $this->actingAsUser();
        Place::create(['id' => 'p1', 'name' => 'Garden', 'category' => 'Outdoor', 'lat' => 1, 'lng' => 1]);
        $cursor = $this->getJson('/api/v1/realtime/events')->json('data.cursor');

        $this->postJson('/api/v1/checkins', [
            'placeId' => 'p1', 'lat' => 1, 'lng' => 1, 'visibleToOthers' => false,
        ])->assertOk();

        $events = collect($this->getJson('/api/v1/realtime/events?after='.$cursor)->json('data.events'));
        $this->assertTrue($events->pluck('type')->contains('checkin.created'));
        $this->assertTrue($events->pluck('type')->contains('xp.updated'));
    }
}
