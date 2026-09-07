<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Domain cleanup: department/year/university/clubs/achievements/projects
// used to live only in the client's SharedPreferences (ProfileBioStore) —
// a real, shared column set on `users` replaces that in Rest mode.
class ProfileBioTest extends TestCase
{
    use RefreshDatabase;

    public function test_me_includes_bio_fields_defaulting_to_null_and_empty_arrays(): void
    {
        $this->actingAsUser();

        $data = $this->getJson('/api/v1/me')->assertOk()->json('data');

        $this->assertNull($data['department']);
        $this->assertNull($data['year']);
        $this->assertNull($data['university']);
        $this->assertEquals([], $data['clubs']);
        $this->assertEquals([], $data['achievements']);
        $this->assertEquals([], $data['projects']);
    }

    public function test_updating_bio_persists_and_is_returned_by_me(): void
    {
        $this->actingAsUser();

        $data = $this->postJson('/api/v1/me/profile', [
            'department' => 'Bilgisayar Mühendisliği',
            'year' => '3',
            'university' => 'ARUCAD',
            'clubs' => ['Robotik Kulübü', 'Satranç Kulübü'],
            'achievements' => ['Hackathon 1.'],
            'projects' => ['Kampüs App'],
        ])->assertOk()->json('data');

        $this->assertEquals('Bilgisayar Mühendisliği', $data['department']);
        $this->assertEquals('3', $data['year']);
        $this->assertEquals('ARUCAD', $data['university']);
        $this->assertEquals(['Robotik Kulübü', 'Satranç Kulübü'], $data['clubs']);
        $this->assertEquals(['Hackathon 1.'], $data['achievements']);
        $this->assertEquals(['Kampüs App'], $data['projects']);

        $again = $this->getJson('/api/v1/me')->assertOk()->json('data');
        $this->assertEquals('Bilgisayar Mühendisliği', $again['department']);
        $this->assertEquals(['Robotik Kulübü', 'Satranç Kulübü'], $again['clubs']);
    }

    public function test_avatar_url_persists_on_the_user_and_is_returned_by_me(): void
    {
        $this->actingAsUser();

        $url = 'http://127.0.0.1:4000/storage/media/avatar-test.jpg';
        $data = $this->postJson('/api/v1/me/profile', ['avatarUrl' => $url])
            ->assertOk()
            ->json('data');

        $this->assertEquals($url, $data['avatarUrl']);
        $this->assertEquals($url, $this->getJson('/api/v1/me')->assertOk()->json('data.avatarUrl'));
    }

    public function test_partial_update_leaves_other_fields_untouched(): void
    {
        $this->actingAsUser();
        $this->postJson('/api/v1/me/profile', [
            'department' => 'Bilgisayar Mühendisliği',
            'university' => 'ARUCAD',
        ])->assertOk();

        $data = $this->postJson('/api/v1/me/profile', ['year' => '2'])->assertOk()->json('data');

        $this->assertEquals('Bilgisayar Mühendisliği', $data['department']);
        $this->assertEquals('ARUCAD', $data['university']);
        $this->assertEquals('2', $data['year']);
    }

    public function test_bio_update_is_scoped_to_the_signed_in_account(): void
    {
        $me = $this->actingAsUser();
        $other = User::create(['name' => 'Other', 'email' => 'other@arucad.edu.tr', 'password' => bcrypt('x')]);

        $this->postJson('/api/v1/me/profile', ['department' => 'Mine'])->assertOk();

        $this->assertEquals('Mine', $me->fresh()->department);
        $this->assertNull($other->fresh()->department);
    }

    public function test_bio_fields_reject_non_string_list_items(): void
    {
        $this->actingAsUser();

        $response = $this->postJson('/api/v1/me/profile', ['clubs' => [123]]);

        $response->assertStatus(400);
        $this->assertEquals('VALIDATION', $response->json('error.code'));
    }

    public function test_updating_bio_requires_a_signed_in_account(): void
    {
        $this->postJson('/api/v1/me/profile', ['department' => 'X'])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'AUTH_REQUIRED');
    }
}
