<?php

namespace Tests\Feature;

use App\Models\DirectoryEntry;
use App\Models\Event;
use App\Models\MediaItem;
use App\Models\ModerationReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Mega2CampusRoutingMediaTest extends TestCase
{
    use RefreshDatabase;

    private function seedDirectory(): void
    {
        DirectoryEntry::create([
            'id' => 'd1', 'building' => 'A Blok', 'floor' => '1', 'room' => '104',
            'occupant_name' => 'Öğrenci İşleri',
        ]);
        DirectoryEntry::create([
            'id' => 'd2', 'building' => 'A Blok', 'floor' => '2', 'room' => '201',
            'occupant_name' => 'Kariyer',
        ]);
        DirectoryEntry::create([
            'id' => 'd3', 'building' => 'Atelier', 'floor' => '1', 'room' => 'Studio',
            'occupant_name' => 'Studio',
        ]);
    }

    public function test_directory_buildings_floors_rooms_drill_down(): void
    {
        $this->seedDirectory();
        $this->actingAsUser();

        $buildings = $this->getJson('/api/v1/directory/buildings')->assertOk()->json('data');
        $this->assertCount(2, $buildings);
        $this->assertSame('A Blok', $buildings[0]['name']);

        $floors = $this->getJson('/api/v1/directory/buildings/'.rawurlencode('A Blok').'/floors')
            ->assertOk()->json('data');
        $this->assertCount(2, $floors);

        $rooms = $this->getJson('/api/v1/directory/buildings/'.rawurlencode('A Blok').'/floors/1/rooms')
            ->assertOk()->json('data');
        $this->assertCount(1, $rooms);
        $this->assertSame('104', $rooms[0]['room']);

        $this->getJson('/api/v1/directory/buildings/Yok/floors')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'BUILDING_NOT_FOUND');

        $filtered = $this->getJson('/api/v1/directory?building='.rawurlencode('Atelier'))
            ->assertOk()->json('data');
        $this->assertCount(1, $filtered);
    }

    public function test_routing_without_provider_is_501(): void
    {
        config(['services.routing.base_url' => null]);
        $this->actingAsUser();

        $this->postJson('/api/v1/routing/directions', [
            'fromLat' => 35.33, 'fromLng' => 33.32,
            'toLat' => 35.34, 'toLng' => 33.31,
        ])->assertStatus(501)->assertJsonPath('error.code', 'ROUTING_NOT_CONFIGURED');
    }

    public function test_routing_with_osrm_response_returns_points(): void
    {
        config(['services.routing.base_url' => 'http://routing.test']);
        Http::fake([
            'routing.test/*' => Http::response([
                'routes' => [[
                    'distance' => 120.5,
                    'duration' => 90.0,
                    'geometry' => [
                        'coordinates' => [[33.32, 35.33], [33.315, 35.335], [33.31, 35.34]],
                    ],
                    'legs' => [[
                        'steps' => [[
                            'distance' => 60,
                            'duration' => 45,
                            'name' => 'Campus Rd',
                            'maneuver' => ['type' => 'turn', 'modifier' => 'left'],
                        ]],
                    ]],
                ]],
            ], 200),
        ]);
        $this->actingAsUser();

        $data = $this->postJson('/api/v1/routing/directions', [
            'fromLat' => 35.33, 'fromLng' => 33.32,
            'toLat' => 35.34, 'toLng' => 33.31,
        ])->assertOk()->json('data');

        $this->assertCount(3, $data['points']);
        $this->assertSame(120.5, $data['distanceMeters']);
        $this->assertNotEmpty($data['steps']);
    }

    public function test_routing_public_osrm_uses_driving_not_foot(): void
    {
        config(['services.routing.base_url' => 'https://router.project-osrm.org']);
        $seen = [];
        Http::fake(function ($request) use (&$seen) {
            $url = $request->url();
            $seen[] = $url;
            if (str_contains($url, 'openstreetmap.de')) {
                return Http::response(['message' => 'unexpected fossgis'], 500);
            }
            if (str_contains($url, '/route/v1/driving/')) {
                return Http::response([
                    'routes' => [[
                        'distance' => 80,
                        'duration' => 60,
                        'geometry' => [
                            'coordinates' => [[33.3213, 35.3373], [33.3210, 35.3378]],
                        ],
                        'legs' => [[
                            'steps' => [[
                                'distance' => 80,
                                'duration' => 60,
                                'name' => 'Sair Nedim',
                                'maneuver' => ['type' => 'depart', 'modifier' => ''],
                            ]],
                        ]],
                    ]],
                ], 200);
            }

            return Http::response(['message' => 'Invalid profile'], 400);
        });
        $this->actingAsUser();

        $data = $this->postJson('/api/v1/routing/directions', [
            'fromLat' => 35.337305, 'fromLng' => 33.321303,
            'toLat' => 35.337754, 'toLng' => 33.321358,
        ])->assertOk()->json('data');

        $this->assertCount(2, $data['points']);
        $this->assertTrue(collect($seen)->contains(fn ($url) => str_contains($url, '/route/v1/driving/')));
        $this->assertFalse(collect($seen)->contains(fn ($url) => str_contains($url, '/route/v1/foot/')));
    }

    public function test_routing_falls_back_when_first_profile_fails(): void
    {
        config(['services.routing.base_url' => 'http://routing.test']);
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, '/route/v1/foot/') || str_contains($url, '/route/v1/walking/')) {
                return Http::response(['message' => 'Invalid profile'], 400);
            }

            return Http::response([
                'routes' => [[
                    'distance' => 40,
                    'duration' => 30,
                    'geometry' => [
                        'coordinates' => [[33.32, 35.33], [33.31, 35.34]],
                    ],
                    'legs' => [],
                ]],
            ], 200);
        });
        $this->actingAsUser();

        $this->postJson('/api/v1/routing/directions', [
            'fromLat' => 35.33, 'fromLng' => 33.32,
            'toLat' => 35.34, 'toLng' => 33.31,
        ])->assertOk()->assertJsonPath('data.distanceMeters', 40);
    }

    public function test_routing_rejects_invalid_coordinates(): void
    {
        config(['services.routing.base_url' => 'http://routing.test']);
        $this->actingAsUser();

        $this->postJson('/api/v1/routing/directions', [
            'fromLat' => 200, 'fromLng' => 33.32,
            'toLat' => 35.34, 'toLng' => 33.31,
        ])->assertStatus(400)->assertJsonPath('error.code', 'INVALID_COORDINATE');
    }

    /**
     * A video whose frames could not actually be inspected is held, not
     * published. Frame extraction needs ffmpeg; when it is unavailable (as
     * in CI, and on the fake 200-byte clip below) nothing about the video's
     * content has been checked, so auto-approving it would be exactly the
     * "publish first, moderate later" gap the policy forbids.
     */
    public function test_video_upload_is_held_for_review_when_frames_cannot_be_inspected(): void
    {
        Storage::fake('public');
        $this->actingAsRole('contentEditor');

        $created = $this->post('/api/v1/media', [
            'file' => UploadedFile::fake()
                ->createWithContent('clip.mp4', "\x00\x00\x00\x18ftypisom".str_repeat('0', 200))
                ->mimeType('video/mp4'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data');

        $this->assertSame('pending', $created['moderationStatus']);
        $this->assertSame('video/mp4', $created['mimeType']);
        $this->assertDatabaseHas('media_items', [
            'id' => $created['id'], 'moderation_status' => 'pending',
        ]);
    }

    public function test_pending_media_queue_review_and_approve(): void
    {
        Storage::fake('public');
        $this->actingAsRole('contentEditor');
        $path = 'media/video/clip.mp4';
        Storage::disk('public')->put($path, "\x00\x00\x00\x18ftypisom".str_repeat('0', 200));
        $item = MediaItem::create([
            'id' => 'media-pending-video',
            'user_id' => null,
            'file_path' => $path,
            'file_name' => 'clip.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 200,
            'uploaded_at' => now(),
            'uploaded_by' => 'editor',
            'used_in' => [],
            'moderation_status' => 'pending',
        ]);
        ModerationReport::create([
            'id' => 'report-pending-video',
            'kind' => 'media',
            'target_id' => $item->id,
            'target_label' => $item->file_name,
            'reason' => 'media_awaiting_local_review',
            'reported_at' => now(),
            'action' => null,
        ]);

        $this->actingAsRole('moderator');
        $queue = $this->getJson('/api/v1/admin/moderation/queue')->assertOk();
        $this->assertSame(1, $queue->json('meta.pagination.total'));
        $reviewUrl = $queue->json('data.0.url');
        $this->assertStringContainsString('signature=', $reviewUrl);
        // Pending material cannot use the public file path, but a moderator
        // can inspect the queue's expiring, signed preview link.
        $this->getJson('/api/v1/media/'.$item->id.'/file')->assertNotFound();
        $this->getJson('/api/v1/media/'.$item->id.'/review-file')->assertForbidden();
        $this->get($reviewUrl)->assertOk();

        $this->postJson('/api/v1/admin/moderation/queue/'.$item->id.'/resolve', [
            'action' => 'approved',
        ])->assertOk()->assertJsonPath('data.moderationStatus', 'approved');

        $this->assertDatabaseHas('media_items', [
            'id' => $item->id, 'moderation_status' => 'approved',
        ]);
    }

    public function test_student_cannot_access_moderation_queue(): void
    {
        $this->actingAsUser();
        $this->getJson('/api/v1/admin/moderation/queue')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_duplicate_queue_resolve_is_conflict(): void
    {
        Storage::fake('public');
        $item = MediaItem::create([
            'id' => 'media-dup-resolve',
            'user_id' => null,
            'file_path' => 'media/video/dup.mp4',
            'file_name' => 'dup.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 100,
            'uploaded_at' => now(),
            'uploaded_by' => 'editor',
            'used_in' => [],
            'moderation_status' => 'pending',
        ]);
        ModerationReport::create([
            'id' => 'report-dup-resolve',
            'kind' => 'media',
            'target_id' => $item->id,
            'target_label' => $item->file_name,
            'reason' => 'media_awaiting_local_review',
            'reported_at' => now(),
            'action' => null,
        ]);

        $this->actingAsRole('moderator');
        $this->postJson('/api/v1/admin/moderation/queue/'.$item->id.'/resolve', ['action' => 'rejected'])
            ->assertOk();
        $this->postJson('/api/v1/admin/moderation/queue/'.$item->id.'/resolve', ['action' => 'approved'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'ALREADY_REVIEWED');
    }

    public function test_poster_draft_without_ai_key_is_501(): void
    {
        config(['services.groq.key' => '']);
        $this->actingAsRole('contentEditor');

        $this->post('/api/v1/admin/events/draft-from-poster', [
            'file' => UploadedFile::fake()->create('poster.jpg', 40, 'image/jpeg'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(501)
            ->assertJsonPath('error.code', 'AI_NOT_CONFIGURED');

        $this->assertDatabaseCount('events', 0);
    }

    public function test_poster_draft_creates_draft_event_never_published(): void
    {
        Storage::fake('public');
        config(['services.groq.key' => 'test-key']);

        Http::fake([
            'api.groq.com/*' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => json_encode([
                            'title' => 'Bahar Şenliği',
                            'time' => '14:00',
                            'eventDate' => '2026-05-15',
                            'placeName' => 'Garden',
                            'category' => 'Etkinlik',
                            'organizer' => 'Konsey',
                            'description' => 'Açık hava',
                        ]),
                    ],
                ]],
            ], 200),
        ]);

        $this->actingAsRole('contentEditor');
        $data = $this->post('/api/v1/admin/events/draft-from-poster', [
            'file' => UploadedFile::fake()->create('poster.jpg', 40, 'image/jpeg'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data');

        $this->assertTrue($data['event']['draft']);
        $this->assertSame('draft', $data['event']['workflowStatus']);
        $this->assertTrue($data['event']['aiDraft']);
        $this->assertFalse($data['incomplete']);
        $this->assertDatabaseHas('events', [
            'id' => $data['event']['id'],
            'workflow_status' => 'draft',
            'ai_draft' => 1,
        ]);
        $this->assertDatabaseHas('admin_audit_log', [
            'action' => 'create', 'target_type' => 'event_ai_draft',
        ]);
    }

    public function test_student_cannot_create_poster_draft(): void
    {
        $this->actingAsUser();
        $this->post('/api/v1/admin/events/draft-from-poster', [
            'file' => UploadedFile::fake()->create('poster.jpg', 40, 'image/jpeg'),
        ], ['Accept' => 'application/json'])
            ->assertStatus(403);
    }
}
