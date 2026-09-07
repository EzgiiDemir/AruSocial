<?php

namespace Tests\Feature;

use App\Models\CareerOpportunity;
use App\Models\Consultation;
use App\Models\SocialFollow;
use App\Models\StaffAvailabilitySlot;
use App\Models\StaffProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CampusOpsCompleteTest extends TestCase
{
    use RefreshDatabase;

    private function staffWithSlot(): StaffProfile
    {
        $staff = StaffProfile::create([
            'id' => 'staff-ops',
            'name' => 'Ops Staff',
            'department' => 'Career',
            'title' => 'Advisor',
            'active' => true,
        ]);
        StaffAvailabilitySlot::create([
            'id' => 'slot-ops-1',
            'staff_profile_id' => $staff->id,
            'slot_date' => '2099-03-01',
            'start_time' => '11:00',
            'end_time' => '11:30',
            'is_blocked' => false,
        ]);

        return $staff;
    }

    public function test_appointment_round_trip_pending_then_admin_approve(): void
    {
        $student = $this->actingAsUser();
        $this->staffWithSlot();

        $created = $this->postJson('/api/v1/appointments', [
            'staffProfileId' => 'staff-ops',
            'date' => '2099-03-01',
            'startTime' => '11:00',
            'endTime' => '11:30',
            'subject' => 'Staj görüşmesi',
            'notes' => 'Portfolyo getiriyorum',
        ])->assertCreated()->json('data');

        $this->assertSame('pending', $created['status']);
        $this->assertSame('Staj görüşmesi', $created['subject']);
        $this->assertDatabaseHas('appointments', [
            'id' => $created['id'],
            'student_user_id' => $student->id,
            'status' => 'pending',
        ]);

        $this->actingAsRole();
        $listed = $this->getJson('/api/v1/admin/appointments')->assertOk()->json('data');
        $this->assertSame($created['id'], $listed[0]['id']);

        $this->postJson('/api/v1/admin/appointments/'.$created['id'], [
            'status' => 'approved',
            'adminNotes' => 'Onaylandı',
        ])->assertOk()->assertJsonPath('data.status', 'approved');

        $this->actingAsUser($student);
        $this->getJson('/api/v1/me/appointments')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'approved')
            ->assertJsonPath('data.0.adminNotes', 'Onaylandı');
    }

    public function test_student_cannot_admin_update_appointment(): void
    {
        $this->actingAsUser();
        $this->staffWithSlot();
        $created = $this->postJson('/api/v1/appointments', [
            'staffProfileId' => 'staff-ops',
            'date' => '2099-03-01',
            'startTime' => '11:00',
            'endTime' => '11:30',
            'subject' => 'Konu',
        ])->assertCreated()->json('data');

        $this->postJson('/api/v1/admin/appointments/'.$created['id'], ['status' => 'approved'])
            ->assertStatus(403);
    }

    public function test_career_cv_apply_and_admin_review(): void
    {
        Storage::fake('local');
        $student = $this->actingAsUser();
        CareerOpportunity::create([
            'id' => 'job-1',
            'title' => 'Junior Designer',
            'kind' => 'job',
            'organization' => 'ARUCAD',
            'department' => 'Tasarım',
            'description' => 'İlan',
            'purpose' => 'Takıma katılım',
            'skills' => 'Figma',
            'published' => true,
            'created_at' => now(),
        ]);

        $this->postJson('/api/v1/me/career-profile', [
            'occupation' => 'Grafiker',
            'expertise' => 'Marka',
            'lookingForJobs' => true,
        ])->assertOk()->assertJsonPath('data.occupation', 'Grafiker');

        $file = UploadedFile::fake()->createWithContent(
            'cv.pdf',
            "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<>\n%%EOF\n",
        );
        $this->post('/api/v1/me/career-profile/cv', ['file' => $file], [
            'Accept' => 'application/json',
        ])->assertOk()->assertJsonPath('data.hasCv', true);

        $this->postJson('/api/v1/career/opportunities/job-1/apply')
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        $this->postJson('/api/v1/career/opportunities/job-1/apply')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'APPLICATION_EXISTS');

        $this->actingAsRole('careerStaff');
        $apps = $this->getJson('/api/v1/admin/career/applications')->assertOk()->json('data');
        $this->assertCount(1, $apps);
        $this->assertSame($student->name, $apps[0]['userName']);

        $this->postJson('/api/v1/admin/career/applications/'.$apps[0]['id'], [
            'status' => 'shortlisted',
            'adminNotes' => 'Mülakata çağır',
        ])->assertOk()->assertJsonPath('data.status', 'shortlisted');

        $this->get('/api/v1/admin/career/applications/'.$apps[0]['id'].'/cv')
            ->assertOk();
    }

    public function test_consultation_apply_and_admin_manage(): void
    {
        $student = $this->actingAsUser();
        Consultation::create([
            'id' => 'consult-1',
            'title' => 'CV Review',
            'purpose' => 'CV güçlendirmek',
            'audience' => 'Öğrenciler',
            'content' => 'Bire bir seans',
            'published' => true,
        ]);

        $this->postJson('/api/v1/consultations/consult-1/apply', ['notes' => 'Staj için'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        $this->postJson('/api/v1/consultations/consult-1/apply')
            ->assertStatus(409);

        $this->actingAsRole('trainer');
        $apps = $this->getJson('/api/v1/admin/consultation-applications')->assertOk()->json('data');
        $this->assertSame($student->name, $apps[0]['userName']);
        $this->postJson('/api/v1/admin/consultation-applications/'.$apps[0]['id'], [
            'status' => 'accepted',
        ])->assertOk()->assertJsonPath('data.status', 'accepted');
    }

    public function test_official_post_pin_and_private_profile_gate(): void
    {
        $author = $this->actingAsUser();
        $this->postJson('/api/v1/feed', ['text' => 'öğrenci post'])->assertOk();

        $this->actingAsRole('contentEditor');
        $official = $this->postJson('/api/v1/admin/feed', ['text' => 'Resmi duyuru'])
            ->assertCreated()
            ->json('data');
        $this->assertTrue($official['official']);

        $this->postJson('/api/v1/feed/'.$official['id'].'/pin')
            ->assertOk()
            ->assertJsonPath('data.isPinned', true);

        $feed = $this->getJson('/api/v1/feed')->assertOk()->json('data');
        $this->assertSame($official['id'], $feed[0]['id']);

        $this->actingAsUser($author);
        $this->postJson('/api/v1/me/settings', ['isPrivateProfile' => true])->assertOk();
        $this->postJson('/api/v1/feed', ['text' => 'gizli post'])->assertOk();

        $stranger = User::create([
            'name' => 'Stranger',
            'email' => 'stranger@arucad.edu.tr',
            'password' => bcrypt('x'),
        ]);
        $this->actingAsUser($stranger);
        $texts = collect($this->getJson('/api/v1/feed')->json('data'))->pluck('text');
        $this->assertFalse($texts->contains('gizli post'));

        $profile = $this->getJson('/api/v1/social/users/'.$author->id)->assertOk()->json('data');
        $this->assertTrue($profile['isLocked']);

        SocialFollow::create([
            'follower_user_id' => $stranger->id,
            'followed_user_id' => $author->id,
        ]);
        $unlocked = $this->getJson('/api/v1/social/users/'.$author->id)->assertOk()->json('data');
        $this->assertFalse($unlocked['isLocked']);
    }

    public function test_friends_are_mutual_follows(): void
    {
        $a = $this->actingAsUser();
        $b = User::create(['name' => 'Bee', 'email' => 'bee@arucad.edu.tr', 'password' => bcrypt('x')]);
        SocialFollow::create(['follower_user_id' => $a->id, 'followed_user_id' => $b->id]);
        $this->getJson('/api/v1/social/friends')->assertOk()->assertJsonCount(0, 'data');

        SocialFollow::create(['follower_user_id' => $b->id, 'followed_user_id' => $a->id]);
        $friends = $this->getJson('/api/v1/social/friends')->assertOk()->json('data');
        $this->assertCount(1, $friends);
        $this->assertSame('Bee', $friends[0]['name']);
    }

    public function test_student_cannot_pin_or_create_official_post(): void
    {
        $this->actingAsUser();
        $post = $this->postJson('/api/v1/feed', ['text' => 'x'])->assertOk()->json('data');
        $this->postJson('/api/v1/feed/'.$post['id'].'/pin')->assertStatus(403);
        $this->postJson('/api/v1/admin/feed', ['text' => 'duyuru'])->assertStatus(403);
    }
}
