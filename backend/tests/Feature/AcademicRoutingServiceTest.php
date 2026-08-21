<?php

namespace Tests\Feature;

use App\Services\AcademicRoutingService;
use Database\Seeders\AcademicStaffSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// docs/EKSIKLER.md aktivite/onay workflow §7 — real department→approver
// auto-routing, tested against the real seeded 59-person roster.
class AcademicRoutingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AcademicStaffSeeder::class);
    }

    public function test_new_media_department_routes_to_its_real_department_head(): void
    {
        $staff = AcademicRoutingService::routeFor('Yeni Medya ve İletişim', 'İletişim Fakültesi');
        $this->assertNotNull($staff);
        $this->assertEquals('Çağdaş Öğüç', $staff->name);
    }

    public function test_digital_game_department_routes_correctly(): void
    {
        $staff = AcademicRoutingService::routeFor('Dijital Oyun Tasarımı', 'İletişim Fakültesi');
        $this->assertEquals('Yunus Luckinger', $staff->name);
    }

    public function test_a_department_with_no_head_falls_back_to_the_faculty_dean(): void
    {
        // Seramik has no is_department_head=true row in the real roster.
        $staff = AcademicRoutingService::routeFor('Seramik', 'Sanat Fakültesi');
        $this->assertNotNull($staff);
        $this->assertEquals('Nur Onat', $staff->name);
        $this->assertTrue($staff->is_faculty_dean);
    }

    public function test_an_unknown_department_and_faculty_routes_to_nobody(): void
    {
        $staff = AcademicRoutingService::routeFor('Uzay Mühendisliği', 'Bilinmeyen Fakülte');
        $this->assertNull($staff);
    }

    public function test_department_alias_matching_is_forgiving_of_student_wording(): void
    {
        $staff = AcademicRoutingService::routeFor('Görsel İletişim', 'İletişim Fakültesi');
        $this->assertEquals('Hakan Karahasan', $staff->name);
    }
}
