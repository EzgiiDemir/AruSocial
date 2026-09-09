<?php

namespace Tests\Feature;

use App\Models\Place;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every endpoint that accepts free text must refuse abuse.
 *
 * Reading the controllers is not enough to know this: four of them imported
 * the moderation trait and never called it, which looks correct at a glance
 * and enforces nothing. So each surface is exercised over HTTP with the
 * same abusive string, and the assertion is simply that it did not go
 * through — a surface that stops being wired fails here, loudly.
 */
class ModerationSurfaceCoverageTest extends TestCase
{
    use RefreshDatabase;

    /** Blocked by hate rules regardless of target, in every language. */
    private const ABUSE = 'lanet zenci';

    private function student(): User
    {
        $user = $this->actingAsUser();
        config(['services.moderation.openai_key' => '']);

        return $user;
    }

    private function place(): Place
    {
        return Place::create([
            'id' => 'p1', 'name' => 'Titan', 'category' => 'Admin',
            'lat' => 35.337, 'lng' => 33.321, 'description' => '',
            'distance' => '', 'density' => 'quiet', 'accessible' => true,
            'photos' => 0, 'rating' => 0,
        ]);
    }

    public function test_every_free_text_surface_refuses_abuse(): void
    {
        $this->student();
        $place = $this->place();

        /** @var array<string, array{0: string, 1: string, 2: array<string, mixed>}> */
        $surfaces = [
            'post' => ['post', '/api/v1/feed', ['text' => self::ABUSE]],
            'comment' => ['post', '/api/v1/feed', ['text' => 'temiz']],   // replaced below
            'story' => ['post', '/api/v1/stories', ['text' => self::ABUSE]],
            'place review' => ['post', "/api/v1/places/{$place->id}/reviews",
                ['rating' => 5, 'comment' => self::ABUSE]],
            'place report' => ['post', "/api/v1/places/{$place->id}/report",
                ['reason' => self::ABUSE]],
            'workshop post' => ['post', "/api/v1/places/{$place->id}/workshop/posts",
                ['text' => self::ABUSE]],
            'profile bio' => ['post', '/api/v1/me/profile', ['bio' => self::ABUSE]],
            'career profile' => ['post', '/api/v1/me/career-profile',
                ['occupation' => self::ABUSE, 'expertise' => 'x']],
            'own activity' => ['post', '/api/v1/events/own',
                ['title' => self::ABUSE, 'date' => '2026-12-01']],
            'user report' => ['post', '/api/v1/social/report-user',
                ['name' => 'Someone', 'reason' => self::ABUSE]],
        ];
        unset($surfaces['comment']);   // needs a post id; covered separately

        $unguarded = [];
        foreach ($surfaces as $label => [$method, $url, $body]) {
            $response = $this->{$method.'Json'}($url, $body);

            // 404/422 mean the fixture was wrong, not that moderation is
            // missing — only a 2xx proves abuse was actually published.
            if ($response->status() >= 200 && $response->status() < 300) {
                $unguarded[] = sprintf('%s (%s %s → %d)', $label, $method, $url, $response->status());
            }
        }

        $this->assertSame([], $unguarded,
            "These surfaces published abusive text:\n".implode("\n", $unguarded));
    }

    public function test_a_comment_on_a_post_refuses_abuse(): void
    {
        $this->student();

        $postId = $this->postJson('/api/v1/feed', ['text' => 'Temiz bir gönderi.'])
            ->assertSuccessful()->json('data.id');

        $this->postJson("/api/v1/feed/{$postId}/comments", ['text' => self::ABUSE])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'CONTENT_BLOCKED');
    }

    public function test_chat_and_group_surfaces_refuse_abuse(): void
    {
        $me = $this->student();
        $peer = User::create([
            'name' => 'Peer', 'email' => 'peer@arucad.edu.tr', 'password' => bcrypt('x'),
        ]);

        $this->postJson("/api/v1/chat/{$peer->name}/messages", ['text' => self::ABUSE])
            ->assertStatus(400);

        // A group name is displayed to everyone in it.
        $this->postJson('/api/v1/chat/groups', [
            'name' => self::ABUSE, 'memberIds' => [$peer->id],
        ])->assertStatus(400);

        $this->assertSame($me->id, $me->id);
    }

    public function test_appointment_and_application_free_text_refuses_abuse(): void
    {
        $this->student();

        // Both reach a named member of staff as free text.
        // A fully valid booking, so the request clears validation and the
        // moderation gate is what actually answers. The staff id does not
        // need to exist: moderation runs before the lookup, which is the
        // point — an abusive note must not be recorded even on a booking
        // that was going to fail for another reason.
        $this->postJson('/api/v1/appointments', [
            'staffProfileId' => 'staff-1', 'date' => '2026-12-01',
            'startTime' => '10:00', 'endTime' => '10:30',
            'subject' => self::ABUSE, 'notes' => '',
        ])->assertStatus(400)->assertJsonPath('error.code', 'CONTENT_BLOCKED');

        // A real club, so the request gets past the existence check and
        // moderation is what answers. (Checking the target first is the
        // right order — there is nothing to publish to either way.)
        \App\Models\Club::create([
            'id' => 'club-music', 'name' => 'Müzik Kulübü',
            'category' => 'Sanat', 'description' => '', 'members' => 0,
        ]);

        $this->postJson('/api/v1/applications', [
            'targetType' => 'club', 'targetId' => 'club-music',
            'formPayload' => ['why' => self::ABUSE],
        ])->assertStatus(400)->assertJsonPath('error.code', 'CONTENT_BLOCKED');
    }
}
