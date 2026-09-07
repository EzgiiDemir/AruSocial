<?php

namespace Tests\Unit;

use App\Support\CampusGeofence;
use PHPUnit\Framework\TestCase;

class CampusGeofenceTest extends TestCase
{
    public function test_garden_is_inside_the_main_campus_polygon(): void
    {
        $this->assertTrue(CampusGeofence::pointInRing(
            35.33715,
            33.32135,
            CampusGeofence::box(35.337395, 33.321358),
        ));
    }

    public function test_kyrenia_street_outside_the_envelope_is_out(): void
    {
        $this->assertFalse(CampusGeofence::pointInRing(
            35.35,
            33.35,
            CampusGeofence::box(35.337395, 33.321358),
        ));
    }
}
