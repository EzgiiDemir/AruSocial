<?php

namespace Tests\Feature;

use App\Services\RoutingService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * End-to-end proof, against live OSRM servers, that a car is not routed
 * over the pedestrian graph.
 *
 * Everything else about the split is covered with fakes in
 * Mega2CampusRoutingMediaTest, which is where the contract belongs. What
 * a fake cannot show is the thing the split exists for: that the two
 * profiles genuinely disagree about how to get from A to B in Northern
 * Cyprus, so pointing a car at the foot graph really does hand back a
 * route no car can drive.
 *
 * Network-dependent, therefore skipped by default. Run it deliberately:
 *
 *   ROUTING_LIVE_TEST=1 php artisan test --filter=TrncRoutingLiveTest
 *
 * It uses the public FOSSGIS instances, which run the same OSRM software
 * and the same two profiles as deploy/osrm builds locally — so a pass
 * here is evidence about the profiles, not about those servers.
 */
class TrncRoutingLiveTest extends TestCase
{
    /** ARUCAD Kyrenia campus, Girne, TRNC. */
    private const CAMPUS_LAT = 35.337395;

    private const CAMPUS_LNG = 33.321358;

    /** Girne (Kyrenia) old harbour, a short walk north. */
    private const HARBOUR_LAT = 35.341944;

    private const HARBOUR_LNG = 33.318611;

    private const FOOT_BASE = 'https://routing.openstreetmap.de/routed-foot';

    private const CAR_BASE = 'https://routing.openstreetmap.de/routed-car';

    protected function setUp(): void
    {
        parent::setUp();

        if (! env('ROUTING_LIVE_TEST')) {
            $this->markTestSkipped('Set ROUTING_LIVE_TEST=1 to run the live TRNC routing check.');
        }

        // RoutingService caches by mode + host for six hours; a live check
        // has to actually reach the network.
        Cache::flush();
    }

    private function configureSplitGraphs(): void
    {
        config([
            'services.routing.base_url' => self::FOOT_BASE,
            'services.routing.driving_base_url' => self::CAR_BASE,
            'services.routing.allow_public_fallback' => false,
            'services.routing.verify_ssl' => false,
        ]);
    }

    public function test_a_real_walking_route_across_girne_comes_back_from_the_foot_graph(): void
    {
        $this->configureSplitGraphs();

        $route = RoutingService::route(
            self::CAMPUS_LAT, self::CAMPUS_LNG,
            self::HARBOUR_LAT, self::HARBOUR_LNG,
            'walking',
        );

        $this->assertNotNull($route, 'Live foot routing returned nothing.');
        $this->assertGreaterThan(2, count($route['points']));
        $this->assertGreaterThan(0, $route['distanceMeters']);

        // Every point stays in Northern Cyprus rather than, say, a
        // mainland graph that happened to answer.
        foreach ($route['points'] as $point) {
            $this->assertEqualsWithDelta(35.34, $point['lat'], 0.05);
            $this->assertEqualsWithDelta(33.32, $point['lng'], 0.05);
        }
    }

    public function test_a_real_driving_route_does_not_reuse_the_pedestrian_geometry(): void
    {
        $this->configureSplitGraphs();

        $walk = RoutingService::route(
            self::CAMPUS_LAT, self::CAMPUS_LNG,
            self::HARBOUR_LAT, self::HARBOUR_LNG,
            'walking',
        );
        $drive = RoutingService::route(
            self::CAMPUS_LAT, self::CAMPUS_LNG,
            self::HARBOUR_LAT, self::HARBOUR_LNG,
            'driving',
        );

        $this->assertNotNull($walk);
        $this->assertNotNull($drive);

        // The two graphs must not agree. A pedestrian can cut through;
        // a car is held to the road network and goes round. If these
        // came back identical, the car request reached the foot graph —
        // exactly the bug the split removes.
        $this->assertNotEquals(
            $walk['points'],
            $drive['points'],
            'Driving geometry is identical to walking geometry: the car request hit the foot graph.',
        );
        $this->assertGreaterThan(
            $walk['distanceMeters'],
            $drive['distanceMeters'],
            'The car route should be longer than the pedestrian one here, since it cannot use the direct path.',
        );
    }

    public function test_a_vehicle_with_no_car_graph_is_unconfigured_rather_than_sent_to_the_footpaths(): void
    {
        config([
            'services.routing.base_url' => self::FOOT_BASE,
            'services.routing.driving_base_url' => null,
            'services.routing.allow_public_fallback' => false,
        ]);

        $this->assertTrue(RoutingService::isConfiguredFor('walking'));
        $this->assertFalse(RoutingService::isConfiguredFor('driving'));
        $this->assertNull(RoutingService::route(
            self::CAMPUS_LAT, self::CAMPUS_LNG,
            self::HARBOUR_LAT, self::HARBOUR_LNG,
            'driving',
        ));
    }
}
