<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Place;
use App\Models\ServiceItem;
use App\Services\Ai\AskOperations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AskOperationalComponentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Place::create(['id' => 'meditation', 'name' => 'Meditation', 'category' => 'Library', 'lat' => 35.3377, 'lng' => 33.3213, 'description' => 'Library', 'distance' => '', 'density' => '', 'street' => '']);
        Place::create(['id' => 'titan', 'name' => 'Titan', 'category' => 'Administration', 'lat' => 35.3380, 'lng' => 33.3220, 'description' => 'Administration', 'distance' => '', 'density' => '', 'street' => '']);
        Place::create(['id' => 'main-entrance', 'name' => 'Ana kampüs girişi', 'category' => 'Entrance', 'lat' => 35.3370, 'lng' => 33.3210, 'description' => 'Entrance', 'distance' => '', 'density' => '', 'street' => '', 'tour_url' => 'https://360.arucad.edu.tr/main?media-name=ENTRY', 'tour_target' => 'ENTRY']);
        ServiceItem::create(['id' => 'student-affairs', 'title' => 'Öğrenci İşleri (Student Affairs)', 'category' => 'Administrative', 'description' => 'Kayıt, belge ve öğrenci işlemleri birimi', 'contact' => 'registrar@example.edu', 'building' => 'Titan']);
        ServiceItem::create(['id' => 'pdr', 'title' => 'Psikolojik Danışmanlık Merkezi (PDR)', 'category' => 'Support', 'description' => 'Gizli ve ücretsiz psikolojik danışmanlık hizmeti', 'contact' => 'support@example.edu', 'building' => 'Minotaur']);
        ServiceItem::create(['id' => 'dormitory', 'title' => 'Yurt (Dormitory)', 'category' => 'Accommodation', 'description' => 'Öğrenci konaklama ve barınma desteği', 'contact' => 'housing@example.edu', 'building' => 'ARUCAD Dormitory']);
        Place::create(['id' => 'minotaur', 'name' => 'Minotaur', 'category' => 'Support', 'lat' => 35.3381, 'lng' => 33.3221, 'description' => 'Support', 'distance' => '', 'density' => '', 'street' => '']);
        Place::create(['id' => 'dormitory', 'name' => 'ARUCAD Dormitory', 'category' => 'Accommodation', 'lat' => 35.3382, 'lng' => 33.3222, 'description' => 'Housing', 'distance' => '', 'density' => '', 'street' => '']);
    }

    public function test_multilingual_service_resolution_builds_verified_place_card(): void
    {
        $result = app(AskOperations::class)->resolve('Where is Student Affairs?');

        $this->assertSame('titan', $result['places'][0]['id']);
        $this->assertSame('student-affairs', $result['places'][0]['serviceId']);
        $this->assertNull($result['route']);
    }

    public function test_route_uses_provider_values_and_real_geometry(): void
    {
        config(['services.routing.base_url' => 'https://routing.test', 'services.routing.driving_base_url' => 'https://driving.test']);
        Http::fake(fn () => Http::response([
            'routes' => [[
                'distance' => 777.0,
                'duration' => 333.0,
                'geometry' => ['coordinates' => [[33.3213, 35.3377], [33.3220, 35.3380]]],
                'legs' => [['steps' => []]],
            ]],
        ], 200));

        $result = app(AskOperations::class)->resolve('How do I get from the library to Student Affairs?');

        $this->assertSame('meditation', $result['route']['origin']['id']);
        $this->assertSame('titan', $result['route']['destination']['id']);
        $this->assertSame(777.0, $result['route']['distanceMeters']);
        $this->assertSame(333.0, $result['route']['durationSeconds']);
        $this->assertCount(2, $result['route']['geometry']);
        $this->assertSame('osrm', $result['route']['provider']);
    }

    public function test_sis_question_returns_unavailable_instead_of_a_guess(): void
    {
        $result = app(AskOperations::class)->resolve('What class do I have today?');

        $this->assertSame('SIS_UNAVAILABLE', $result['warnings'][0]['code']);
        $this->assertStringContainsString('SIS', $result['answer']);
    }

    public function test_aliases_match_whole_words_not_substrings(): void
    {
        Place::create(['id' => 'eve', 'name' => 'Eve', 'category' => 'Art', 'lat' => 35.34, 'lng' => 33.32, 'description' => 'Artwork', 'distance' => '', 'density' => '', 'street' => '']);
        ServiceItem::create(['id' => 'it', 'title' => 'Bilgi İşlem (IT)', 'category' => 'Support', 'description' => 'Wi-Fi support', 'contact' => 'it@example.edu', 'building' => 'Titan']);

        $events = app(AskOperations::class)->resolve('what events are on today');
        $architecture = app(AskOperations::class)->resolve('architecture programme');

        $this->assertSame([], $events['places']);
        $this->assertSame('There are no publicly listed campus events today.', $events['answer']);
        $this->assertSame([], $architecture['places']);
    }

    public function test_english_class_timetable_paraphrase_is_safely_rejected(): void
    {
        $result = app(AskOperations::class)->resolve('what is my class timetable today');

        $this->assertSame('SIS_UNAVAILABLE', $result['warnings'][0]['code']);
        $this->assertStringContainsString('not connected', $result['answer']);
    }

    public function test_counselling_and_dormitory_use_their_canonical_service_rows(): void
    {
        $counselling = app(AskOperations::class)->resolve('psikolojik danışmanlık nasıl randevu alırım');
        $dormitory = app(AskOperations::class)->resolve('yurt hakkında bilgi');

        $this->assertSame('pdr', $counselling['places'][0]['serviceId']);
        $this->assertStringContainsString('psikolojik danışmanlık', $counselling['answer']);
        $this->assertSame('dormitory', $dormitory['places'][0]['serviceId']);
        $this->assertStringContainsString('konaklama', $dormitory['answer']);
    }

    /**
     * A Turkish service description must not be pasted into an English
     * answer.
     *
     * Measured: "What are the library rules?" returned "Library: Sanat,
     * tasarım ve iletişim odaklı kaynaklar. Location: Meditation." — English
     * labels wrapped around Turkish prose, which reads as an answer in
     * neither language and failed the eval's language check. The structured
     * fields are language-neutral and stay; the free text is dropped rather
     * than machine-translated, because an unverified translation of a service
     * description is exactly what this deterministic layer exists to avoid.
     */
    public function test_a_turkish_blurb_is_not_pasted_into_an_english_answer(): void
    {
        $english = app(AskOperations::class)->resolve('Tell me about Student Affairs');
        $turkish = app(AskOperations::class)->resolve('Öğrenci İşleri hakkında bilgi ver');

        // The Turkish prose never appears in the English answer...
        $this->assertStringNotContainsString('öğrenci işlemleri birimi', (string) $english['answer']);
        // ...but the facts a student asked for still do.
        $this->assertStringContainsString('Titan', (string) $english['answer']);
        $this->assertStringContainsString('registrar@example.edu', (string) $english['answer']);

        // And the Turkish answer keeps the blurb it was written for.
        $this->assertStringContainsString('öğrenci işlemleri birimi', (string) $turkish['answer']);
    }

    /**
     * The structured catalog answers topic questions, not figures it does not
     * store.
     *
     * "2027 güz döneminde mimarlık bölümünün taban puanı" used to match the
     * architecture row on the words "mimarlık bölümü" and return the
     * programme blurb — not the requested figure, not a refusal, and it took
     * the question away from the model and the grounding check that would
     * have declined it properly. We hold no admission scores, and no row
     * covers a future academic year.
     */
    public function test_the_catalog_declines_figures_it_does_not_hold(): void
    {
        // Positive control: an ordinary question about the same service is
        // still answered deterministically.
        $ordinary = app(AskOperations::class)->resolve('Öğrenci İşleri nerede');
        $this->assertNotNull($ordinary['answer']);
        $this->assertStringContainsString('Titan', (string) $ordinary['answer']);

        // A score we do not store, asked for as a figure.
        $score = app(AskOperations::class)->resolve('Öğrenci İşleri taban puanı kaç');
        $this->assertNull($score['answer']);

        // A period no row can cover.
        $future = app(AskOperations::class)->resolve(
            '2035 yılında Öğrenci İşleri nerede olacak',
        );
        $this->assertNull($future['answer']);
    }

    /**
     * A listing question gets the catalog; a recommendation gets the model.
     *
     * "Ben mimarlık öğrencisiyim" followed by "hangi kulüplere katılmalıyım?"
     * used to return all nineteen clubs in alphabetical order, ignoring the
     * department the student had just volunteered. The list is not wrong, it
     * is simply not an answer to what was asked — and the deterministic path
     * took the question away from the only layer that could have used the
     * context.
     */
    public function test_a_recommendation_is_not_answered_with_a_catalog_listing(): void
    {
        Club::create(['id' => 'photo', 'name' => 'Fotoğraf Kulübü', 'description' => 'Fotoğraf', 'category' => 'Sanat']);
        Club::create(['id' => 'arch', 'name' => 'Mimarlık Kulübü', 'description' => 'Mimarlık', 'category' => 'Tasarım']);

        // A listing question is still served deterministically, from the rows.
        $listing = app(AskOperations::class)->resolve('hangi kulüpler var');
        $this->assertNotNull($listing['answer']);
        $this->assertStringContainsString('Fotoğraf Kulübü', (string) $listing['answer']);

        // A recommendation is handed on to the model instead.
        $this->assertNull(app(AskOperations::class)->resolve('hangi kulüplere katılmalıyım')['answer']);
        $this->assertNull(app(AskOperations::class)->resolve('which club should i join')['answer']);
        $this->assertNull(app(AskOperations::class)->resolve('какой клуб мне выбрать')['answer']);
    }

    /**
     * Weekday hours must not be offered as the answer to a weekend question.
     *
     * This is the most dangerous error class in the system, and the only one
     * no other layer can catch. "Kütüphane pazar günü kaçta açılıyor?"
     * returned "Saatler: Hafta içi 09:00–17:00" — a real figure, read from our
     * own row, presented as the Sunday opening time. AnswerGrounding cannot
     * flag it, because nothing was invented; the number is simply an answer to
     * a different question.
     */
    public function test_a_weekend_question_is_not_answered_with_weekday_hours(): void
    {
        ServiceItem::where('id', 'student-affairs')->update(['hours' => 'Hafta içi 09:00–17:00']);

        foreach ([
            'Öğrenci İşleri pazar günü kaçta açılıyor?' => 'hafta sonu',
            'Is Student Affairs open on Sunday?' => 'weekend',
            'Отдел по работе со студентами работает в воскресенье?' => 'выходные',
        ] as $question => $mustMention) {
            $answer = (string) app(AskOperations::class)->resolve($question)['answer'];

            $this->assertNotSame('', $answer, "No answer for: {$question}");
            $this->assertStringContainsStringIgnoringCase(
                $mustMention, $answer,
                "Did not flag missing weekend hours for: {$question}",
            );
        }

        // A plain hours question is still answered directly.
        $this->assertStringContainsString(
            '09:00',
            (string) app(AskOperations::class)->resolve('Öğrenci İşleri saatleri nedir?')['answer'],
        );
    }

    /**
     * A two-letter service id must not match an ordinary word.
     *
     * The IT service's id is `it`, so "Is there a gym on campus, and is it
     * free for students?" resolved to IT Support and answered with the IT
     * office's address. The whole-word boundary cannot help when the word
     * genuinely is "it"; the department stays reachable by its longer aliases.
     */
    public function test_the_english_pronoun_it_does_not_match_the_it_department(): void
    {
        ServiceItem::create(['id' => 'it', 'title' => 'Bilgi İşlem (IT)', 'category' => 'Support',
            'description' => 'Wi-Fi desteği', 'contact' => 'it@example.edu', 'building' => 'Titan']);

        $pronoun = app(AskOperations::class)->resolve('Is there a gym on campus, and is it free?');
        $this->assertSame([], $pronoun['places'], 'The pronoun "it" resolved to a place card.');

        // The department is still findable by a real name.
        $real = app(AskOperations::class)->resolve('Bilgi İşlem nerede?');
        $this->assertSame('it', $real['places'][0]['serviceId'] ?? null);
    }

    /**
     * Getting to the campus itself is a route question with no campus place
     * in it, and it used to be refused outright.
     */
    public function test_reaching_the_campus_is_answered_rather_than_refused(): void
    {
        foreach ([
            'Kampüse nasıl ulaşırım?',
            'How do I get to campus from the airport?',
            'Как добраться до кампуса из аэропорта Эрджан?',
        ] as $question) {
            $result = app(AskOperations::class)->resolve($question);
            $answer = (string) $result['answer'];

            $this->assertNotSame('', $answer, "No answer for: {$question}");
            $this->assertStringContainsString('arucad.edu.tr', $answer);
            $this->assertStringNotContainsStringIgnoringCase('doğrulayamadım', $answer);
            $this->assertStringNotContainsStringIgnoringCase('Не удалось подтвердить', $answer);
        }
    }

    /**
     * A question the location row cannot answer goes to the model, with the
     * place card still attached.
     */
    public function test_a_process_question_is_not_answered_with_a_location_card(): void
    {
        $documents = app(AskOperations::class)->resolve(
            'I am an international student. What documents do I need?',
        );

        $this->assertNull($documents['answer'], 'A documents question was answered with a card.');

        // "Where is it" is still a lookup and still answered here.
        $this->assertNotNull(
            app(AskOperations::class)->resolve('Where is Student Affairs?')['answer'],
        );
    }

    public function test_360_request_returns_a_real_tour_action_without_the_llm(): void
    {
        $result = app(AskOperations::class)->resolve(
            '360 şekilde ana kampüs girişini açar mısın',
        );

        $this->assertSame('main-entrance', $result['places'][0]['id']);
        $this->assertSame(
            'https://360.arucad.edu.tr/main?media-name=ENTRY',
            $result['places'][0]['tourUrl'],
        );
        $this->assertSame('ENTRY', $result['places'][0]['tourTarget']);
        $this->assertStringContainsString('360° Aç', $result['answer']);
    }

    /**
     * "Oraya" is a real destination — it is just named in an earlier turn.
     *
     * Resolving places from this message alone answered a contextual
     * follow-up with "I could not verify the destination", which is the
     * conversation visibly forgetting what was just said.
     */
    public function test_a_follow_up_resolves_its_destination_from_the_conversation(): void
    {
        config(['services.routing.base_url' => 'https://routing.test']);
        Http::fake(fn () => Http::response([
            'routes' => [[
                'distance' => 120.0,
                'duration' => 90.0,
                'geometry' => ['coordinates' => [[33.3213, 35.3377], [33.3220, 35.3380]]],
                'legs' => [['steps' => []]],
            ]],
        ], 200));

        $result = app(AskOperations::class)->resolve(
            'Peki oraya nasıl giderim?',
            ['lat' => 35.3370, 'lng' => 33.3210],
            'walking',
            'Öğrenci İşleri nerede? Peki oraya nasıl giderim?',
        );

        $this->assertSame('titan', $result['route']['destination']['id']);
        $this->assertSame([], $result['warnings']);
    }

    /**
     * The conversation may supply the PLACE, never the INTENT: a route asked
     * two turns ago must not turn a later opening-hours question into a
     * route.
     */
    public function test_conversation_context_does_not_change_what_the_question_asks_for(): void
    {
        $result = app(AskOperations::class)->resolve(
            'Kaçta kapanıyor?',
            null,
            'walking',
            'Öğrenci İşleri’ne nasıl giderim? Kaçta kapanıyor?',
        );

        $this->assertNull($result['route']);
        $this->assertSame('titan', $result['places'][0]['id']);
    }
}
