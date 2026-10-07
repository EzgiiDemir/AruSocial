<?php

namespace Tests\Feature;

use App\Models\DirectoryEntry;
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

    public function test_map_matching_returns_the_snapped_latest_fix(): void
    {
        config([
            'services.routing.base_url' => 'http://walk.test',
            'services.routing.driving_base_url' => 'http://car.test',
        ]);
        Http::fake(function ($request) {
            $this->assertStringContainsString('/match/v1/driving/', $request->url());
            $this->assertStringContainsString('timestamps=', $request->url());
            $this->assertStringContainsString('radiuses=', $request->url());

            return Http::response([
                'tracepoints' => [
                    ['location' => [33.32001, 35.33001]],
                    ['location' => [33.32020, 35.33020]],
                ],
                'matchings' => [[
                    'confidence' => 0.91,
                    'geometry' => ['coordinates' => [
                        [33.32001, 35.33001],
                        [33.32020, 35.33020],
                    ]],
                ]],
            ], 200);
        });
        $this->actingAsUser();

        $this->postJson('/api/v1/routing/match', [
            'mode' => 'driving',
            'samples' => [
                ['lat' => 35.33000, 'lng' => 33.32000, 'accuracy' => 8, 'timestamp' => 1_795_000_000],
                ['lat' => 35.33019, 'lng' => 33.32019, 'accuracy' => 7, 'timestamp' => 1_795_000_002],
            ],
        ])->assertOk()
            ->assertJsonPath('data.lat', 35.3302)
            ->assertJsonPath('data.lng', 33.3202)
            ->assertJsonPath('data.confidence', 0.91)
            ->assertJsonCount(2, 'data.points');
    }

    public function test_map_matching_rejects_a_single_gps_sample(): void
    {
        config(['services.routing.base_url' => 'http://walk.test']);
        Http::fake();
        $this->actingAsUser();

        $this->postJson('/api/v1/routing/match', [
            'samples' => [
                ['lat' => 35.33, 'lng' => 33.32, 'accuracy' => 8, 'timestamp' => 1_795_000_000],
            ],
        ])->assertStatus(400)
            ->assertJsonPath('error.code', 'VALIDATION');

        Http::assertNothingSent();
    }

    /*
     * An OSRM instance serves one profile, and it answers
     * /route/v1/driving/ from a pedestrian graph just the same. Before
     * the split, a "car" route was walking geometry down footpaths with
     * nothing to indicate it.
     */
    public function test_a_vehicle_route_uses_the_car_graph_when_one_is_configured(): void
    {
        config([
            'services.routing.base_url' => 'http://walk.test',
            'services.routing.driving_base_url' => 'http://car.test',
        ]);
        $seen = [];
        Http::fake(function ($request) use (&$seen) {
            $seen[] = $request->url();

            return Http::response([
                'routes' => [[
                    'distance' => 900.0,
                    'duration' => 180.0,
                    'geometry' => ['coordinates' => [[33.32, 35.33], [33.31, 35.34]]],
                    'legs' => [[]],
                ]],
            ], 200);
        });
        $this->actingAsUser();

        $this->postJson('/api/v1/routing/directions', [
            'fromLat' => 35.33, 'fromLng' => 33.32,
            'toLat' => 35.34, 'toLng' => 33.31,
            'mode' => 'driving',
        ])->assertOk();

        $this->assertNotEmpty($seen);
        foreach ($seen as $url) {
            $this->assertStringContainsString('car.test', $url);
            $this->assertStringNotContainsString('walk.test', $url);
        }
    }

    public function test_walking_still_uses_the_pedestrian_graph(): void
    {
        config([
            'services.routing.base_url' => 'http://walk.test',
            'services.routing.driving_base_url' => 'http://car.test',
        ]);
        $seen = [];
        Http::fake(function ($request) use (&$seen) {
            $seen[] = $request->url();

            return Http::response([
                'routes' => [[
                    'distance' => 120.0,
                    'duration' => 90.0,
                    'geometry' => ['coordinates' => [[33.32, 35.33], [33.31, 35.34]]],
                    'legs' => [[]],
                ]],
            ], 200);
        });
        $this->actingAsUser();

        $this->postJson('/api/v1/routing/directions', [
            'fromLat' => 35.33, 'fromLng' => 33.32,
            'toLat' => 35.34, 'toLng' => 33.31,
            'mode' => 'walking',
        ])->assertOk();

        foreach ($seen as $url) {
            $this->assertStringContainsString('walk.test', $url);
        }
    }

    /*
     * The whole point of the split. A pedestrian graph answers
     * /route/v1/driving/ without complaint, so borrowing it for a car
     * produced a real-looking route down stairs and footpaths with
     * nothing on screen saying so. With no car graph the mode is simply
     * unconfigured: 501, and the app draws its honest straight line.
     */
    public function test_a_vehicle_route_never_borrows_the_pedestrian_graph(): void
    {
        config([
            'services.routing.base_url' => 'http://osrm-foot.test',
            'services.routing.driving_base_url' => null,
            'services.routing.allow_public_fallback' => false,
        ]);
        Http::fake();
        $this->actingAsUser();

        foreach (['driving', 'transit'] as $mode) {
            $this->postJson('/api/v1/routing/directions', [
                'fromLat' => 35.33, 'fromLng' => 33.32,
                'toLat' => 35.34, 'toLng' => 33.31,
                'mode' => $mode,
            ])->assertStatus(501)->assertJsonPath('error.code', 'ROUTING_NOT_CONFIGURED');
        }

        Http::assertNothingSent();
    }

    /*
     * ...and the reverse: a missing car graph must not take walking down
     * with it. Pedestrian routing is the one the campus depends on most.
     */
    public function test_walking_still_works_when_only_the_foot_graph_exists(): void
    {
        config([
            'services.routing.base_url' => 'http://osrm-foot.test',
            'services.routing.driving_base_url' => null,
            'services.routing.allow_public_fallback' => false,
        ]);
        Http::fake(['osrm-foot.test/*' => Http::response([
            'routes' => [[
                'distance' => 120.0,
                'duration' => 90.0,
                'geometry' => ['coordinates' => [[33.32, 35.33], [33.31, 35.34]]],
                'legs' => [[]],
            ]],
        ], 200)]);
        $this->actingAsUser();

        $this->postJson('/api/v1/routing/directions', [
            'fromLat' => 35.33, 'fromLng' => 33.32,
            'toLat' => 35.34, 'toLng' => 33.31,
            'mode' => 'walking',
        ])->assertOk()->assertJsonPath('data.distanceMeters', 120);
    }

    /*
     * The compose stack's own values (deploy/docker-compose.yml). Walking
     * must reach osrm-foot and vehicles osrm-car, with no crossover in
     * either direction.
     */
    public function test_the_compose_service_urls_route_each_mode_to_its_own_graph(): void
    {
        config([
            'services.routing.base_url' => 'http://osrm-foot:5000',
            'services.routing.driving_base_url' => 'http://osrm-car:5000',
            'services.routing.allow_public_fallback' => false,
        ]);
        $this->actingAsUser();

        foreach ([
            'walking' => ['osrm-foot:5000', 'osrm-car:5000'],
            'driving' => ['osrm-car:5000', 'osrm-foot:5000'],
            'transit' => ['osrm-car:5000', 'osrm-foot:5000'],
        ] as $mode => [$expected, $forbidden]) {
            $seen = [];
            Http::fake(function ($request) use (&$seen) {
                $seen[] = $request->url();

                return Http::response([
                    'routes' => [[
                        'distance' => 500.0,
                        'duration' => 120.0,
                        'geometry' => ['coordinates' => [[33.32, 35.33], [33.31, 35.34]]],
                        'legs' => [[]],
                    ]],
                ], 200);
            });

            $this->postJson('/api/v1/routing/directions', [
                // Real ARUCAD campus coordinates, Kyrenia / Girne, TRNC.
                'fromLat' => 35.337395, 'fromLng' => 33.321358,
                'toLat' => 35.341944, 'toLng' => 33.318611,
                'mode' => $mode,
            ])->assertOk()->assertJsonPath('data.mode', $mode);

            $this->assertNotEmpty($seen, "{$mode} sent no request");
            foreach ($seen as $url) {
                $this->assertStringContainsString($expected, $url, "{$mode} must use {$expected}");
                $this->assertStringNotContainsString($forbidden, $url, "{$mode} must never touch {$forbidden}");
            }
        }
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
        // The client phrases the turn itself, so it needs the maneuver,
        // not only the flattened English string built from it.
        $this->assertSame('turn', $data['steps'][0]['type']);
        $this->assertSame('left', $data['steps'][0]['modifier']);
        $this->assertSame('Campus Rd', $data['steps'][0]['name']);
        $this->assertSame('left turn', $data['steps'][0]['instruction']);
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

    /*
     * Updated when walking and vehicles were split onto separate OSRM
     * instances: a vehicle no longer borrows the pedestrian graph, so
     * this now has to configure the car host it is meant to reach. The
     * behaviour under test — vehicles ask for /route/v1/driving/ and the
     * response echoes the mode — is unchanged.
     */
    public function test_driving_and_transit_use_the_road_graph_and_echo_mode(): void
    {
        config([
            'services.routing.base_url' => 'http://routing.test',
            'services.routing.driving_base_url' => 'http://routing.test',
        ]);
        $seen = [];
        Http::fake(function ($request) use (&$seen) {
            $seen[] = $request->url();

            return Http::response([
                'routes' => [[
                    'distance' => 90,
                    'duration' => 70,
                    'geometry' => [
                        'coordinates' => [[33.32, 35.33], [33.31, 35.34]],
                    ],
                    'legs' => [],
                ]],
            ], 200);
        });
        $this->actingAsUser();

        foreach (['driving', 'transit'] as $mode) {
            $this->postJson('/api/v1/routing/directions', [
                'fromLat' => 35.33, 'fromLng' => 33.32,
                'toLat' => 35.34, 'toLng' => 33.31,
                'mode' => $mode,
            ])->assertOk()->assertJsonPath('data.mode', $mode);
        }

        $this->assertNotEmpty($seen);
        $this->assertTrue(collect($seen)->every(
            fn ($url) => str_contains($url, '/route/v1/driving/'),
        ));
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
     * The fixture is an image because video was removed on 14 September
     * 2026. The test is about the review queue, not about the file type —
     * held media reaching a moderator, a signed preview link, and the
     * resolve action — so it keeps its coverage with a photo.
     */
    public function test_pending_media_queue_review_and_approve(): void
    {
        Storage::fake(MediaItem::disk());
        Storage::fake('local');
        $this->actingAsRole('contentEditor');
        $path = 'media/held.jpg';
        Storage::disk(MediaItem::disk())->put($path, "\xFF\xD8\xFF\xE0".str_repeat('0', 200));
        $item = MediaItem::create([
            'id' => 'media-pending-photo',
            'user_id' => null,
            'file_path' => $path,
            'file_name' => 'held.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 200,
            'uploaded_at' => now(),
            'uploaded_by' => 'editor',
            'used_in' => [],
            'moderation_status' => 'pending',
        ]);
        ModerationReport::create([
            'id' => 'report-pending-photo',
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
        Storage::fake(MediaItem::disk());
        Storage::fake('local');
        $item = MediaItem::create([
            'id' => 'media-dup-resolve',
            'user_id' => null,
            'file_path' => 'media/dup.jpg',
            'file_name' => 'dup.jpg',
            'mime_type' => 'image/jpeg',
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
        Storage::fake(MediaItem::disk());
        Storage::fake('local');
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
