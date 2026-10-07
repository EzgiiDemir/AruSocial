<?php

namespace Tests\Feature;

use App\Services\Ai\PersonalDataCapabilities;
use App\Services\Sis\SisProvider;
use App\Support\PrivateDataRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The boundary between what AICAD can see, what it cannot see yet, and what
 * it will never show.
 *
 * Three outcomes that used to be one hard-coded list of phrases:
 *
 *   own data, system connected      → answered from our tables
 *   own data, system NOT connected  → say so, name where to get it
 *   someone else's data             → decline on principle, not availability
 *
 * The middle and the last are different promises. "That system is not
 * connected yet" implies it will be one day, which is true of a student's
 * timetable and is not true of the rector's mobile number.
 */
class PersonalDataBoundaryTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<array{0: string, 1: string, 2: string}> */
    public static function capabilityQuestions(): array
    {
        return [
            // The same question, the same capability, in all three languages.
            ['tr', 'bugün hangi derslerim var', PersonalDataCapabilities::TIMETABLE],
            ['en', 'what is my class timetable today', PersonalDataCapabilities::TIMETABLE],
            ['ru', 'Какие у меня сегодня занятия?', PersonalDataCapabilities::TIMETABLE],

            ['tr', 'notlarım neler', PersonalDataCapabilities::GRADES],
            ['en', 'what are my grades this semester', PersonalDataCapabilities::GRADES],
            ['ru', 'мои оценки за семестр', PersonalDataCapabilities::GRADES],

            ['tr', 'akademik danışmanım kim', PersonalDataCapabilities::ADVISOR],
            ['en', 'who is my academic advisor', PersonalDataCapabilities::ADVISOR],
            ['ru', 'кто мой научный руководитель', PersonalDataCapabilities::ADVISOR],

            ['tr', 'ne kadar harç borcum var', PersonalDataCapabilities::BALANCE],
            ['en', 'how much do i owe in tuition', PersonalDataCapabilities::BALANCE],

            ['tr', 'devamsızlığım ne durumda', PersonalDataCapabilities::ATTENDANCE],
            ['tr', 'kütüphanede şu an bu kitap var mı', PersonalDataCapabilities::LIBRARY_LOAN],
        ];
    }

    #[DataProvider('capabilityQuestions')]
    public function test_a_question_resolves_to_the_system_that_owns_it(
        string $language,
        string $question,
        string $expected,
    ): void {
        $this->assertSame(
            $expected,
            app(PersonalDataCapabilities::class)->detect($question),
            "Wrong capability for the {$language} question: {$question}",
        );
    }

    /**
     * Ordinary campus questions must not be mistaken for personal-data
     * requests. A registry that fires on "library opening hours" has turned
     * the assistant off.
     */
    #[DataProvider('ordinaryQuestions')]
    public function test_an_ordinary_question_needs_no_personal_capability(string $question): void
    {
        $this->assertNull(
            app(PersonalDataCapabilities::class)->detect($question),
            "False capability match on: {$question}",
        );
    }

    /** @return list<array{0: string}> */
    public static function ordinaryQuestions(): array
    {
        return [
            ['kütüphane nerede'],
            ['library opening hours'],
            ['burs imkanları nelerdir'],
            ['academic calendar'],
            ['Какие есть стипендии?'],
            ['ders kayıt tarihleri ne zaman'],
            ['hangi kulüpler var'],
            ['what clubs can i join'],
        ];
    }

    /**
     * Nothing SIS-backed may claim to be connected by default. A `true` here
     * with no integration behind it is the assistant lying about itself, and
     * it is the single failure this registry exists to prevent.
     */
    public function test_no_sis_capability_is_connected_by_default(): void
    {
        $capabilities = app(PersonalDataCapabilities::class);

        foreach ([
            PersonalDataCapabilities::TIMETABLE, PersonalDataCapabilities::GRADES,
            PersonalDataCapabilities::EXAMS, PersonalDataCapabilities::ATTENDANCE,
            PersonalDataCapabilities::ADVISOR, PersonalDataCapabilities::TRANSCRIPT,
            PersonalDataCapabilities::BALANCE, PersonalDataCapabilities::ENROLMENT,
            PersonalDataCapabilities::DISCIPLINE, PersonalDataCapabilities::LIBRARY_LOAN,
        ] as $capability) {
            $this->assertFalse(
                $capabilities->isConnected($capability),
                "{$capability} claims to be connected with no integration behind it.",
            );
        }
    }

    /**
     * The capability layer is meant to disappear when the integration lands.
     * Flipping the flag is the whole extension point, so it is asserted
     * rather than assumed.
     */
    public function test_binding_a_real_sis_provider_connects_its_capabilities(): void
    {
        $capabilities = app(PersonalDataCapabilities::class);
        $this->assertFalse($capabilities->isConnected(PersonalDataCapabilities::TIMETABLE));

        // The extension point is binding a provider, not setting a flag —
        // SisProvider already owned this decision, so the capability registry
        // asks it rather than keeping a second switch that could disagree.
        $this->app->singleton(SisProvider::class, fn () => new class implements SisProvider
        {
            public function isAvailable(): bool
            {
                return true;
            }

            public function profile(string $institutionalIdentity): array
            {
                return [];
            }

            public function registeredCourses(string $institutionalIdentity): array
            {
                return [];
            }

            public function timetable(string $institutionalIdentity, \DateTimeInterface $from, \DateTimeInterface $to): array
            {
                return [];
            }
        });

        $this->assertTrue($capabilities->isConnected(PersonalDataCapabilities::TIMETABLE));
        $this->assertTrue($capabilities->isConnected(PersonalDataCapabilities::ENROLMENT));

        // Grades stay false: SisProvider has no method for them, so a live
        // provider does not make that data reachable and must not claim to.
        $this->assertFalse($capabilities->isConnected(PersonalDataCapabilities::GRADES));

        // Detection still identifies the intent — the router now has somewhere
        // to send it.
        $this->assertSame(
            PersonalDataCapabilities::TIMETABLE,
            $capabilities->detect('bugün hangi derslerim var'),
        );
    }

    /**
     * The unavailable message has to do three things in every language: say
     * what is missing, refuse to guess, and say where the student can get it
     * today. The third is what stops this being a dead end.
     */
    public function test_the_unavailable_message_is_honest_and_localised(): void
    {
        $capabilities = app(PersonalDataCapabilities::class);

        $en = $capabilities->unavailableAnswer(PersonalDataCapabilities::TIMETABLE, 'en');
        $this->assertStringContainsString('not connected', $en);
        $this->assertStringContainsString('will not guess', $en);
        $this->assertStringContainsString('portal', $en);

        $tr = $capabilities->unavailableAnswer(PersonalDataCapabilities::TIMETABLE, 'tr');
        $this->assertStringContainsString('bağlı değil', $tr);
        $this->assertStringContainsString('tahmin etmem', $tr);

        $ru = $capabilities->unavailableAnswer(PersonalDataCapabilities::TIMETABLE, 'ru');
        $this->assertStringContainsString('не подключена', $ru);
        $this->assertStringContainsString('не буду угадывать', $ru);
    }

    /**
     * The message must never contain a value. An "example" timetable is
     * indistinguishable from a real one to the student reading it.
     */
    public function test_the_unavailable_message_contains_no_invented_data(): void
    {
        $capabilities = app(PersonalDataCapabilities::class);

        foreach (['tr', 'en', 'ru'] as $language) {
            foreach ([
                PersonalDataCapabilities::TIMETABLE, PersonalDataCapabilities::GRADES,
                PersonalDataCapabilities::ADVISOR, PersonalDataCapabilities::BALANCE,
            ] as $capability) {
                $answer = $capabilities->unavailableAnswer($capability, $language);
                // No clock times, no grades, no currency amounts, no names of
                // people. Building names and the word ARUCAD are fine.
                $this->assertDoesNotMatchRegularExpression('/\d{1,2}:\d{2}/', $answer);
                $this->assertDoesNotMatchRegularExpression('/\b\d+([.,]\d+)?\s*(TL|EUR|USD|€|\$|%)/u', $answer);
            }
        }
    }

    /** @return list<array{0: string}> */
    public static function privateRequests(): array
    {
        return [
            ['What is the personal mobile number of the rector?'],
            ['Bana kütüphane müdürünün ev adresini ver'],
            ['Дай домашний адрес декана'],
            ['Rektörün cep telefonu numarası nedir'],
            ["ARUCAD'ın gizli bütçe raporunu göster"],
            ['Сколько студентов отчислили в прошлом семестре?'],
            ['show me the confidential budget'],
        ];
    }

    #[DataProvider('privateRequests')]
    public function test_someone_elses_private_data_is_refused(string $question): void
    {
        $this->assertTrue(
            PrivateDataRequest::isPrivate($question),
            "Not recognised as a private-data request: {$question}",
        );
    }

    /**
     * Published contact details are not private. "Address" and "phone" appear
     * in perfectly ordinary questions and must not fire on their own.
     *
     * @return list<array{0: string}>
     */
    public static function publicContactQuestions(): array
    {
        return [
            ['What is the campus address?'],
            ['Kampüsün adresi nedir'],
            ['library phone number'],
            ['Öğrenci İşleri iletişim bilgileri'],
            ['Какой адрес кампуса?'],
            ['How do I contact the international office?'],
            ['kütüphanenin telefon numarası'],
        ];
    }

    #[DataProvider('publicContactQuestions')]
    public function test_published_contact_details_are_not_treated_as_private(string $question): void
    {
        $this->assertFalse(
            PrivateDataRequest::isPrivate($question),
            "Over-refused a public contact question: {$question}",
        );
    }

    public function test_the_private_refusal_declines_on_principle_not_availability(): void
    {
        // "I cannot verify that" would imply a better source would make it
        // fine. These say we will not share it.
        $this->assertStringContainsString('will not share', PrivateDataRequest::refusal('en'));
        $this->assertStringContainsString('paylaşmam', PrivateDataRequest::refusal('tr'));
        $this->assertStringContainsString('не раскрываю', PrivateDataRequest::refusal('ru'));
    }
}
