<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Event;
use App\Models\FoodVenue;
use App\Models\Place;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\Ai\DirectAnswer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The questions that must never reach a language model.
 *
 * Each has exactly one correct answer in our own tables. Sending them to
 * a model spends a shared quota on a lookup and introduces the one
 * failure this system cannot tolerate — an invented fact.
 *
 * The other half of these tests is the harder half: everything this
 * path must DECLINE, so the assistant stays a conversation rather than
 * a vending machine.
 */
class DirectAnswerTest extends TestCase
{
    use RefreshDatabase;

    private function ask(string $question, ?User $user = null): ?string
    {
        return app(DirectAnswer::class)->tryAnswer($question, $user, now());
    }

    private function library(): Place
    {
        return Place::create([
            'id' => 'meditation', 'name' => 'Meditation', 'category' => 'Academic',
            'lat' => 35.3373, 'lng' => 33.3213, 'street' => 'A Blok',
            'description' => 'Kütüphane ve çalışma salonları burada.',
            'accessible' => true,
        ]);
    }

    // ------------------------------------------------------------ answers

    public function test_where_is_a_place(): void
    {
        $this->library();

        $answer = $this->ask('Meditation nerede?');

        $this->assertNotNull($answer);
        $this->assertStringContainsString('A Blok', $answer);
        $this->assertStringContainsString('Engelli erişimine uygun', $answer);
    }

    public function test_the_same_question_in_english(): void
    {
        $this->library();

        $answer = $this->ask('Where is Meditation?');

        $this->assertStringContainsString('is on campus', (string) $answer);
        $this->assertStringContainsString('Step-free access', (string) $answer);
    }

    public function test_the_same_question_in_russian(): void
    {
        $this->library();

        $this->assertStringContainsString('находится в кампусе', (string) $this->ask('Где Meditation?'));
    }

    public function test_a_place_typed_without_turkish_diacritics(): void
    {
        Place::create([
            'id' => 'kutuphane', 'name' => 'Kütüphane', 'category' => 'Academic',
            'lat' => 35.3, 'lng' => 33.3, 'street' => 'B Blok',
        ]);

        $this->assertStringContainsString('B Blok', (string) $this->ask('kutuphane nerede'));
    }

    public function test_the_longest_matching_name_wins(): void
    {
        Place::create(['id' => 'c', 'name' => 'Campus', 'category' => 'X', 'lat' => 1, 'lng' => 1,
            'street' => 'Yanlış']);
        Place::create(['id' => 'nbc', 'name' => 'Nicosia Bandabuliya Campus', 'category' => 'X',
            'lat' => 2, 'lng' => 2, 'street' => 'Doğru']);

        $this->assertStringContainsString('Doğru',
            (string) $this->ask('Nicosia Bandabuliya Campus nerede?'));
    }

    public function test_whats_on_today(): void
    {
        Event::create([
            'id' => 'ev-today', 'title' => 'Sergi Açılışı', 'time' => '18:00',
            'event_date' => now()->toDateString(), 'place_name' => 'Galeri', 'category' => 'Sanat',
        ]);

        $answer = $this->ask('Bugün etkinlik var mı?');

        $this->assertStringContainsString('Sergi Açılışı', (string) $answer);
        $this->assertStringContainsString('Galeri', (string) $answer);
    }

    /** An empty day is a fact, and saying so beats a model guessing. */
    public function test_an_empty_day_is_stated_plainly(): void
    {
        $this->assertStringContainsString('etkinlik yok', (string) $this->ask('bugün etkinlik var mı'));
    }

    public function test_a_draft_event_is_never_announced(): void
    {
        Event::create([
            'id' => 'ev-draft', 'title' => 'Gizli Taslak', 'time' => '18:00',
            'event_date' => now()->toDateString(), 'place_name' => 'Galeri',
            'category' => 'Sanat', 'draft' => true,
        ]);

        $this->assertStringNotContainsString('Gizli Taslak', (string) $this->ask('bugün etkinlik var mı'));
    }

    public function test_canteen_hours(): void
    {
        FoodVenue::create(['id' => 'garden', 'name' => 'The Garden', 'hours' => '08:00 - 19:00']);

        $this->assertStringContainsString('08:00 - 19:00', (string) $this->ask('yemekhane saatleri'));
    }

    public function test_my_next_appointment(): void
    {
        $me = User::factory()->create();
        $staff = StaffProfile::create(['id' => 'st-1', 'name' => 'Danışman', 'title' => 'Öğrenci İşleri']);
        Appointment::create([
            'id' => 'ap-1', 'staff_profile_id' => $staff->id, 'student_user_id' => $me->id,
            'slot_date' => now()->addDays(3)->toDateString(), 'start_time' => '11:00',
            'end_time' => '11:30', 'status' => 'confirmed', 'subject' => 'Ders seçimi',
        ]);

        $answer = $this->ask('Randevum ne zaman?', $me);

        $this->assertStringContainsString('Ders seçimi', (string) $answer);
        $this->assertStringContainsString(now()->addDays(3)->format('d.m.Y'), (string) $answer);
    }

    public function test_no_appointment_is_stated_rather_than_guessed(): void
    {
        $me = User::factory()->create();

        $this->assertStringContainsString('randevun görünmüyor', (string) $this->ask('Randevum ne zaman?', $me));
    }

    // ----------------------------------------------------------- declines

    /** A second clause means a conversation. That is the model's job. */
    public function test_a_multi_part_question_goes_to_the_model(): void
    {
        $this->library();

        $this->assertNull($this->ask(
            'Meditation nerede, oraya nasıl giderim ve akşam kaça kadar açık kalıyor?',
        ));
    }

    public function test_advice_goes_to_the_model(): void
    {
        $this->assertNull($this->ask('Hangi bölümü seçmeliyim?'));
    }

    /**
     * Knowing the name is not knowing the answer: with no address and
     * no description the fast path would say "X is on campus", which
     * tells someone asking where it is nothing at all.
     */
    public function test_a_place_we_hold_no_detail_for_goes_to_the_model(): void
    {
        Place::create([
            'id' => 'bare', 'name' => 'Rodin', 'category' => 'Admin',
            'lat' => 1, 'lng' => 1,
        ]);

        $this->assertNull($this->ask('Rodin nerede?'));
    }

    public function test_an_unknown_place_goes_to_the_model(): void
    {
        $this->library();

        $this->assertNull($this->ask('Kuantum laboratuvarı nerede?'));
    }

    /**
     * The one that matters most. Someone describing distress must never
     * be handed a catalogue row, however their sentence is shaped.
     */
    public function test_distress_never_takes_the_fast_path(): void
    {
        $this->library();

        $this->assertNull($this->ask('çok kötüyüm, kimse beni istemiyor'));
    }

    public function test_an_appointment_question_from_a_stranger_is_declined(): void
    {
        $this->assertNull($this->ask('Randevum ne zaman?', null));
    }

    public function test_it_can_be_switched_off_entirely(): void
    {
        config(['ai.direct_answers.enabled' => false]);
        $this->library();

        $this->assertNull($this->ask('Meditation nerede?'));
    }

    // -------------------------------------------------------- end to end

    /** The whole point: a lookup must not reach the provider. */
    public function test_a_lookup_never_calls_the_model(): void
    {
        config(['ai.providers.groq.api_key' => 'sk-test']);
        $this->actingAsUser();
        $this->library();
        Http::fake();

        $response = $this->postJson('/api/v1/ai/query', ['prompt' => 'Meditation nerede?']);

        $response->assertOk()->assertJsonPath('data.aiMode', 'direct');
        $this->assertStringContainsString('A Blok', (string) $response->json('data.answer'));
        Http::assertNothingSent();
    }
}
