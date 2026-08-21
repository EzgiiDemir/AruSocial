<?php

namespace Tests\Feature;

use Database\Seeders\AcademicStaffSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// docs/EKSIKLER.md aktivite/onay workflow §8/§10 — real personnel
// directory backing the admin "E-posta" recipient picker.
class AcademicStaffApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_the_real_academic_staff_directory(): void
    {
        $this->actingAsAdmin();
        $this->seed(AcademicStaffSeeder::class);

        $response = $this->getJson('/api/v1/admin/academic-staff');

        $response->assertOk();
        $rows = $response->json('data');
        $this->assertCount(59, $rows);
        $this->assertTrue(collect($rows)->contains(fn ($r) => $r['name'] === 'Çağdaş Öğüç' && $r['isDepartmentHead']));
    }

    public function test_a_plain_student_cannot_list_academic_staff(): void
    {
        $this->actingAsUser(role: 'student');
        $this->seed(AcademicStaffSeeder::class);

        $this->getJson('/api/v1/admin/academic-staff')->assertStatus(403);
    }
}
