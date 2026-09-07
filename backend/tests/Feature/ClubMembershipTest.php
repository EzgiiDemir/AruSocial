<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\ClubMember;
use App\Models\ParticipationApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

// Membership is granted via approved application (or direct ClubMember row
// for cascade tests). POST /clubs/{id}/join requires an approved application.
class ClubMembershipTest extends TestCase
{
    use RefreshDatabase;

    private function seedClub(string $id = 'club-1'): Club
    {
        return Club::create(['id' => $id, 'name' => 'Test Club', 'category' => 'Sanat']);
    }

    private function approveJoin(User $user, Club $club): void
    {
        ParticipationApplication::create([
            'id' => 'app-'.Str::uuid(),
            'user_id' => $user->id,
            'target_type' => 'club',
            'target_id' => $club->id,
            'status' => 'approved',
            'form_payload' => [],
            'submitted_at' => now(),
        ]);
    }

    public function test_joining_without_approved_application_is_forbidden(): void
    {
        $this->actingAsUser();
        $club = $this->seedClub();

        $this->postJson("/api/v1/clubs/{$club->id}/join")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'APPLICATION_REQUIRED');
        $this->assertDatabaseCount('club_members', 0);
    }

    public function test_joining_creates_a_row_owned_by_the_signed_in_account(): void
    {
        $me = $this->actingAsUser();
        $club = $this->seedClub();
        $this->approveJoin($me, $club);

        $data = $this->postJson("/api/v1/clubs/{$club->id}/join")->assertOk()->json('data');

        $this->assertTrue($data['joined']);
        $this->assertDatabaseHas('club_members', ['club_id' => $club->id, 'user_id' => $me->id]);
    }

    public function test_joining_twice_does_not_duplicate_or_unjoin(): void
    {
        $me = $this->actingAsUser();
        $club = $this->seedClub();
        $this->approveJoin($me, $club);

        $this->postJson("/api/v1/clubs/{$club->id}/join")->assertOk();
        $data = $this->postJson("/api/v1/clubs/{$club->id}/join")->assertOk()->json('data');

        $this->assertTrue($data['joined']);
        $this->assertEquals(1, ClubMember::where('club_id', $club->id)->where('user_id', $me->id)->count());
    }

    public function test_leaving_removes_the_row(): void
    {
        $me = $this->actingAsUser();
        $club = $this->seedClub();
        $this->approveJoin($me, $club);
        $this->postJson("/api/v1/clubs/{$club->id}/join")->assertOk();

        $data = $this->postJson("/api/v1/clubs/{$club->id}/leave")->assertOk()->json('data');

        $this->assertFalse($data['joined']);
        $this->assertDatabaseMissing('club_members', ['club_id' => $club->id, 'user_id' => $me->id]);
    }

    public function test_leaving_a_club_never_joined_is_a_harmless_no_op(): void
    {
        $this->actingAsUser();
        $club = $this->seedClub();

        $this->postJson("/api/v1/clubs/{$club->id}/leave")
            ->assertOk()
            ->assertJsonPath('data.joined', false);
    }

    public function test_rejoining_after_leaving_works(): void
    {
        $me = $this->actingAsUser();
        $club = $this->seedClub();
        $this->approveJoin($me, $club);

        $this->postJson("/api/v1/clubs/{$club->id}/join")->assertOk();
        $this->postJson("/api/v1/clubs/{$club->id}/leave")->assertOk();
        $data = $this->postJson("/api/v1/clubs/{$club->id}/join")->assertOk()->json('data');

        $this->assertTrue($data['joined']);
        $this->assertDatabaseHas('club_members', ['club_id' => $club->id, 'user_id' => $me->id]);
    }

    public function test_joining_a_club_that_does_not_exist_is_a_404(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/v1/clubs/yok/join')
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'CLUB_NOT_FOUND');
    }

    public function test_join_and_leave_require_a_signed_in_account(): void
    {
        $club = $this->seedClub();

        $this->postJson("/api/v1/clubs/{$club->id}/join")
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'AUTH_REQUIRED');
        $this->postJson("/api/v1/clubs/{$club->id}/leave")
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'AUTH_REQUIRED');
        $this->assertDatabaseCount('club_members', 0);
    }

    public function test_membership_list_only_returns_the_current_users_clubs(): void
    {
        $clubA = $this->seedClub('club-a');
        $clubB = $this->seedClub('club-b');
        $other = User::create(['name' => 'Other', 'email' => 'other@arucad.edu.tr', 'password' => bcrypt('x')]);

        $this->actingAsUser($other);
        $this->approveJoin($other, $clubA);
        $this->postJson("/api/v1/clubs/{$clubA->id}/join")->assertOk();

        $me = $this->actingAsUser();
        $this->approveJoin($me, $clubB);
        $this->postJson("/api/v1/clubs/{$clubB->id}/join")->assertOk();

        $ids = $this->getJson('/api/v1/club-memberships')->assertOk()->json('data');

        $this->assertEquals(['club-b'], $ids);
    }

    public function test_deleting_a_club_takes_its_memberships_with_it(): void
    {
        $me = $this->actingAsUser();
        $club = $this->seedClub();
        ClubMember::create(['user_id' => $me->id, 'club_id' => $club->id, 'created_at' => now()]);

        $club->delete();

        $this->assertDatabaseCount('club_members', 0);
    }

    public function test_deleting_a_user_takes_their_memberships_with_it(): void
    {
        $me = $this->actingAsUser();
        $club = $this->seedClub();
        ClubMember::create(['user_id' => $me->id, 'club_id' => $club->id, 'created_at' => now()]);

        $me->delete();

        $this->assertDatabaseCount('club_members', 0);
    }
}
