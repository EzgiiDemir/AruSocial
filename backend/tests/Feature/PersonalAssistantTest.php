<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Club;
use App\Models\ClubMember;
use App\Models\Event;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\Agent\PersonalContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What the assistant may know about the student it is talking to.
 *
 * The feature is "answer my questions about my own campus life". The
 * constraint is that it can only ever be *my* life: the scope comes from
 * the session, never from the question, so no wording can point it at
 * somebody else.
 */
class PersonalAssistantTest extends TestCase
{
    use RefreshDatabase;

    private function student(string $email): User
    {
        return User::factory()->create(['email' => $email]);
    }

    private function appointmentFor(User $user, string $date, string $subject): Appointment
    {
        $staff = StaffProfile::create([
            'id' => 'staff-'.uniqid(),
            'name' => 'Danışman',
            'title' => 'Öğrenci İşleri',
        ]);

        return Appointment::create([
            'id' => 'appt-'.uniqid(),
            'staff_profile_id' => $staff->id,
            'student_user_id' => $user->id,
            'slot_date' => $date,
            'start_time' => '10:00',
            'end_time' => '10:30',
            'status' => 'confirmed',
            'subject' => $subject,
        ]);
    }

    private function lines(User $user): string
    {
        return implode("\n", app(PersonalContext::class)->lines($user, now()));
    }

    // ------------------------------------------------------------ the point

    public function test_it_knows_my_next_appointment(): void
    {
        $me = $this->student('me@arucad.edu.tr');
        $this->appointmentFor($me, now()->addDays(2)->toDateString(), 'Kayıt danışmanlığı');

        $this->assertStringContainsString('Kayıt danışmanlığı', $this->lines($me));
    }

    public function test_it_knows_which_clubs_i_belong_to(): void
    {
        $me = $this->student('member@arucad.edu.tr');
        $club = Club::create(['id' => 'club-foto', 'name' => 'Fotoğraf Kulübü', 'category' => 'Sanat']);
        ClubMember::create(['user_id' => $me->id, 'club_id' => $club->id, 'created_at' => now()]);

        $this->assertStringContainsString('Fotoğraf Kulübü', $this->lines($me));
    }

    public function test_it_knows_my_level_and_department(): void
    {
        $me = $this->student('profile@arucad.edu.tr');
        $me->forceFill(['department' => 'Mimarlık', 'level' => 3, 'xp' => 1200])->save();

        $lines = $this->lines($me->fresh());

        $this->assertStringContainsString('Mimarlık', $lines);
        $this->assertStringContainsString('1200', $lines);
    }

    // ------------------------------------------------------- the constraint

    /**
     * The assertion this class exists for. There is no code path that
     * takes a name from the question, so no phrasing can widen the scope.
     */
    public function test_it_never_returns_another_students_appointments(): void
    {
        $me = $this->student('mine@arucad.edu.tr');
        $someone = $this->student('other@arucad.edu.tr');
        $this->appointmentFor($someone, now()->addDay()->toDateString(), 'Gizli görüşme');

        $this->assertStringNotContainsString('Gizli görüşme', $this->lines($me));
    }

    public function test_it_never_returns_another_students_clubs(): void
    {
        $me = $this->student('a@arucad.edu.tr');
        $someone = $this->student('b@arucad.edu.tr');
        $club = Club::create(['id' => 'club-x', 'name' => 'Gizli Kulüp', 'category' => 'X']);
        ClubMember::create(['user_id' => $someone->id, 'club_id' => $club->id, 'created_at' => now()]);

        $this->assertStringNotContainsString('Gizli Kulüp', $this->lines($me));
    }

    /** No session, no personal block — not an empty one, and never a guess. */
    public function test_an_anonymous_conversation_has_no_personal_block(): void
    {
        $this->assertSame([], app(PersonalContext::class)->lines(null, now()));
    }

    public function test_general_campus_question_does_not_request_personal_context(): void
    {
        $context = app(PersonalContext::class);

        $this->assertFalse($context->isRelevant('Kampüste nerede çalışabilirim?'));
        $this->assertFalse($context->isRelevant('Ana kampüs girişini 360 aç'));
        $this->assertTrue($context->isRelevant('Bir sonraki randevum ne zaman?'));
        $this->assertTrue($context->isRelevant('Kulüplerim hangileri?'));
    }

    /**
     * A student asking a casual question has not agreed to have their
     * disciplinary record read back at them — and a prompt is logged,
     * cached and sent to a provider.
     */
    public function test_moderation_history_is_never_in_the_prompt(): void
    {
        $me = $this->student('flagged@arucad.edu.tr');
        $me->forceFill([
            'strikes' => 3,
            'moderation_status' => 'warned',
            'moderation_reason' => 'HAR: hakaret',
            'posting_restricted_until' => now()->addDay(),
        ])->save();

        $lines = $this->lines($me->fresh());

        foreach (['strike', 'HAR', 'hakaret', 'warned', 'restricted'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $lines);
        }
    }

    // ------------------------------------------------------------ freshness

    public function test_past_appointments_are_not_presented_as_upcoming(): void
    {
        $me = $this->student('past@arucad.edu.tr');
        $this->appointmentFor($me, now()->subWeek()->toDateString(), 'Geçmiş görüşme');

        $this->assertStringNotContainsString('Geçmiş görüşme', $this->lines($me));
    }

    public function test_a_cancelled_appointment_is_not_listed(): void
    {
        $me = $this->student('cancelled@arucad.edu.tr');
        $appointment = $this->appointmentFor($me, now()->addDay()->toDateString(), 'İptal edilen');
        $appointment->update(['status' => 'cancelled']);

        $this->assertStringNotContainsString('İptal edilen', $this->lines($me));
    }

    public function test_this_weeks_events_are_included_with_their_date(): void
    {
        $me = $this->student('events@arucad.edu.tr');
        Event::create([
            'id' => 'ev-1', 'title' => 'Sergi Açılışı', 'time' => '18:00',
            'event_date' => now()->addDays(2)->toDateString(),
            'place_name' => 'Galeri', 'category' => 'Sanat',
        ]);

        $lines = $this->lines($me);

        $this->assertStringContainsString('Sergi Açılışı', $lines);
        $this->assertStringContainsString(now()->addDays(2)->format('d.m.Y'), $lines);
    }

    public function test_an_unpublished_event_is_never_shown_to_a_student(): void
    {
        $me = $this->student('draft@arucad.edu.tr');
        Event::create([
            'id' => 'ev-draft', 'title' => 'Taslak Etkinlik', 'time' => '18:00',
            'event_date' => now()->addDay()->toDateString(),
            'place_name' => 'Galeri', 'category' => 'Sanat',
            'draft' => true,
        ]);

        $this->assertStringNotContainsString('Taslak Etkinlik', $this->lines($me));
    }
}
