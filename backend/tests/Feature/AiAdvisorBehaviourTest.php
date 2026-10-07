<?php

namespace Tests\Feature;

use App\Models\KnowledgeDocument;
use App\Services\Ai\AskPromptBuilder;
use App\Services\Ai\FollowUpQuery;
use App\Support\QueryLanguage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * AICAD answers in the language the user wrote in, and its prompt tells it to
 * reason like an advisor rather than only quoting sources.
 */
class AiAdvisorBehaviourTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsUser();
        config([
            'services.groq.key' => 'test-key',
            'ai.provider' => 'groq',
            // Groq is an EXTERNAL provider and is now opt-in: the privacy
            // policy refuses it by default so a student's prompt never
            // leaves campus by accident. BOTH switches are needed here,
            // because these tests act as a signed-in user and such a
            // request is classified as carrying personal data. These
            // tests are about Groq's own mechanics, so they turn it on.
            'ai.privacy.allow_external' => true,
            'ai.privacy.allow_external_with_personal_data' => true,
            'ai.providers.groq.api_key' => 'test-key',
            'ai.cache.enabled' => false,
        ]);
        $_ENV['GROQ_API_KEY'] = 'test-key';
        $_SERVER['GROQ_API_KEY'] = 'test-key';
    }

    protected function tearDown(): void
    {
        config(['services.groq.key' => null]);
        unset($_ENV['GROQ_API_KEY'], $_SERVER['GROQ_API_KEY']);
        parent::tearDown();
    }

    /**
     * Regression: an ordinary English sentence carries none of the short
     * function words the old detector looked for, so it scored zero for both
     * languages, no language instruction was sent, and the Turkish system
     * prompt pulled the answer into Turkish.
     */
    public function test_detects_the_language_of_an_ordinary_sentence(): void
    {
        $this->assertSame('en', QueryLanguage::detect('I feel nervous about starting university next month. Any advice?'));
        $this->assertSame('en', QueryLanguage::detect('Which department suits me best?'));
        $this->assertSame('ru', QueryLanguage::detect('Какие программы есть в ARUCAD?'));
        $this->assertSame('tr', QueryLanguage::detect('ARUCADda hangi bölüm bana uygun olur'));
        $this->assertSame('tr', QueryLanguage::detect('burs var mi'));
        $this->assertSame('tr', QueryLanguage::detect('Merhaba'));
    }

    /** @return list<array{0: string, 1: string}> */
    public static function languageProvider(): array
    {
        return [
            'english' => ['Which department suits me best?', 'English'],
            'turkish' => ['Hangi bölüm bana uygun olur?', 'Türkçe'],
            'russian' => ['Какие программы есть в ARUCAD?', 'Русский'],
        ];
    }

    #[DataProvider('languageProvider')]
    public function test_the_prompt_names_the_language_to_answer_in(string $prompt, string $expected): void
    {
        Http::fake(['api.groq.com/*' => Http::response([
            'choices' => [['message' => ['content' => 'ok']]],
        ], 200)]);

        $this->postJson('/api/v1/ai/query', ['prompt' => $prompt])->assertOk();

        Http::assertSent(function ($request) use ($expected) {
            $system = $request->data()['messages'][0]['content'];

            return str_contains($system, "BU SORUNUN DİLİ: {$expected}")
                && str_contains($system, "TAMAMINI {$expected} dilinde yaz");
        });
    }

    public function test_the_prompt_asks_for_advisor_behaviour_not_only_quoting(): void
    {
        Http::fake(['api.groq.com/*' => Http::response([
            'choices' => [['message' => ['content' => 'ok']]],
        ], 200)]);

        $this->postJson('/api/v1/ai/query', ['prompt' => 'Bana hangi bölüm uygun?'])->assertOk();

        Http::assertSent(function ($request) {
            $system = $request->data()['messages'][0]['content'];

            return
                // Reasons with its own knowledge for advice…
                str_contains($system, 'KENDİ bilgini ve muhakemeni kullan')
                // …asks before recommending…
                && str_contains($system, 'HEMEN bir seçim yapma')
                // …but ARUCAD-specific facts stay sourced.
                && str_contains($system, 'YALNIZCA')
                && str_contains($system, 'uydurma');
        });
    }

    /** Reasoning models must not leak their chain of thought into the reply. */
    public function test_reasoning_output_is_hidden_on_groq_requests(): void
    {
        Http::fake(['api.groq.com/*' => Http::response([
            'choices' => [['message' => ['content' => 'ok']]],
        ], 200)]);

        $this->postJson('/api/v1/ai/query', ['prompt' => 'Merhaba'])->assertOk();

        Http::assertSent(fn ($request) => ($request->data()['reasoning_format'] ?? null) === 'hidden');
    }

    /**
     * Regression: once AICAD started giving comparisons, the model answered
     * with a markdown table. The client renders plain text, so the user saw
     * raw "| Avantaj | Açıklama |" and "|---------|----------|" pipes.
     */
    public function test_markdown_tables_are_flattened_into_readable_text(): void
    {
        $answer = "Karşılaştırma:\n"
            ."| Seçenek | Avantaj |\n"
            ."|---------|---------|\n"
            ."| Grafik Tasarım | Yaratıcılık |\n"
            ."| Endüstriyel Tasarım | Ürün odaklı |\n";

        Http::fake(['api.groq.com/*' => Http::response([
            'choices' => [['message' => ['content' => $answer]]],
        ], 200)]);

        $text = $this->postJson('/api/v1/ai/query', ['prompt' => 'Grafik mi endüstriyel mi?'])
            ->assertOk()
            ->json('data.answer');

        $this->assertStringNotContainsString('|', $text);
        $this->assertStringNotContainsString('---', $text);
        // The content itself survives, just flattened.
        $this->assertStringContainsString('Grafik Tasarım — Yaratıcılık', $text);
        $this->assertStringContainsString('Endüstriyel Tasarım — Ürün odaklı', $text);
    }

    // ---------------------------------------------------------------- currency
    // Regression for an answer that gave invented 2024-2025 "taban puan" figures
    // (145,672 / 112,345 / 178,901 — none of which existed in any source) as if
    // they were current, in September 2026.

    /**
     * A short follow-up is anaphoric: retrieving on it alone found a page that
     * merely mentioned the words, not the programme actually being discussed.
     */
    public function test_a_short_follow_up_is_retrieved_with_the_previous_turn(): void
    {
        KnowledgeDocument::create([
            'id' => KnowledgeDocument::idForUrl('https://aday.arucad.edu.tr/rt-program/arkeoloji/'),
            'url' => 'https://aday.arucad.edu.tr/rt-program/arkeoloji/',
            'domain' => 'aday.arucad.edu.tr', 'title' => 'Arkeoloji',
            'content' => 'Arkeoloji programı kazı ve müze çalışmalarını kapsar.',
            'content_hash' => 'ark', 'content_length' => 60, 'fetched_at' => now(),
        ]);

        Http::fake(['api.groq.com/*' => Http::response([
            'choices' => [['message' => ['content' => 'ok']]],
        ], 200)]);

        $this->postJson('/api/v1/ai/query', ['messages' => [
            ['role' => 'user', 'content' => 'Arkeoloji bölümü hakkında bilgi ver'],
            ['role' => 'assistant', 'content' => 'Arkeoloji programı ARUCAD bünyesindedir.'],
            ['role' => 'user', 'content' => 'taban puanlar?'],
        ]])->assertOk();

        Http::assertSent(fn ($request) => str_contains(
            $request->data()['messages'][0]['content'], 'Arkeoloji',
        ));
    }

    /** A question that stands on its own is retrieved as-is. */
    public function test_a_self_contained_question_is_not_padded_with_history(): void
    {

        $long = 'ARUCAD grafik tasarım bölümünün ders içerikleri nelerdir acaba';
        $messages = [
            ['role' => 'user', 'content' => 'Önceki konu tamamen başka bir şeydi'],
            ['role' => 'user', 'content' => $long],
        ];

        $this->assertSame($long, FollowUpQuery::resolve($messages, $long));
    }

    /**
     * A follow-up does not become self-contained by being long.
     *
     * The subject was restored only for questions under 40 characters, so
     * this one — which is nothing but anaphora — was retrieved with no
     * subject at all and matched whichever page happened to discuss
     * graduates.
     */
    public function test_a_long_follow_up_is_still_retrieved_with_its_subject(): void
    {

        $subject = 'Arkeoloji bölümü hakkında bilgi ver';
        $followUp = 'Peki bu bölümün mezunları genelde nerelerde çalışıyor?';
        $messages = [
            ['role' => 'user', 'content' => $subject],
            ['role' => 'assistant', 'content' => 'Arkeoloji programı ARUCAD bünyesindedir.'],
            ['role' => 'user', 'content' => $followUp],
        ];

        $this->assertSame(
            $subject.' '.$followUp,
            FollowUpQuery::resolve($messages, $followUp),
        );
    }

    /**
     * Two follow-ups in a row still resolve to the subject.
     *
     * Stopping at the turn immediately before restored "taban puanlar?",
     * which names nothing either — the walk has to continue back to the
     * turn that actually said which programme is meant.
     */
    public function test_a_chain_of_follow_ups_walks_back_to_the_real_subject(): void
    {

        $subject = 'Arkeoloji bölümü hakkında bilgi ver';
        $messages = [
            ['role' => 'user', 'content' => $subject],
            ['role' => 'assistant', 'content' => 'Arkeoloji programı ARUCAD bünyesindedir.'],
            ['role' => 'user', 'content' => 'taban puanlar?'],
            ['role' => 'assistant', 'content' => 'Bu bilgiyi kaynaklarda bulamadım.'],
            ['role' => 'user', 'content' => 'ya burslar?'],
        ];

        $this->assertSame(
            $subject.' ya burslar?',
            FollowUpQuery::resolve($messages, 'ya burslar?'),
        );
    }

    /**
     * Carrying context forward is a judgement call, and the prompt has to
     * ask for BOTH halves of it: use what the student said when it changes
     * the answer, and leave it out when it does not.
     */
    public function test_the_prompt_asks_for_continuity_and_for_restraint(): void
    {
        $prompt = app(AskPromptBuilder::class)->text('Merhaba');

        $this->assertStringContainsString('KONUŞMANIN SÜREKLİLİĞİ', $prompt);
        // Remember what they volunteered…
        $this->assertStringContainsString('İLGİLİYSE kullan', $prompt);
        // …but do not force a connection that does not exist.
        $this->assertStringContainsString('hiçbir şeyi zorla bağlama', $prompt);
        // And never launder something the student said into campus fact.
        $this->assertStringContainsString('ARUCAD verisi DEĞİLDİR', $prompt);
    }

    /** English and Russian follow-ups are anaphoric in exactly the same way. */
    public function test_follow_ups_are_recognised_in_english_and_russian(): void
    {

        $english = 'What about the tuition fees for that programme in total?';
        $this->assertSame(
            'Tell me about the Archaeology programme '.$english,
            FollowUpQuery::resolve([
                ['role' => 'user', 'content' => 'Tell me about the Archaeology programme'],
                ['role' => 'user', 'content' => $english],
            ], $english),
        );

        $russian = 'А что насчёт стоимости обучения на этой программе?';
        $this->assertSame(
            'Расскажите о программе археологии '.$russian,
            FollowUpQuery::resolve([
                ['role' => 'user', 'content' => 'Расскажите о программе археологии'],
                ['role' => 'user', 'content' => $russian],
            ], $russian),
        );
    }

    /** The model must be told the date; otherwise it states its training-era year. */
    public function test_the_prompt_states_todays_date_and_academic_year(): void
    {
        Http::fake(['api.groq.com/*' => Http::response([
            'choices' => [['message' => ['content' => 'ok']]],
        ], 200)]);

        $this->postJson('/api/v1/ai/query', ['prompt' => 'Merhaba'])->assertOk();

        Http::assertSent(function ($request) {
            $system = $request->data()['messages'][0]['content'];

            return str_contains($system, 'current_date: '.now()->format('d.m.Y'))
                && str_contains($system, 'current_academic_year: ')
                && str_contains($system, 'GÜNCELLİK');
        });
    }

    /** The academic year rolls over in September, not in January. */
    public function test_academic_year_rolls_over_in_september(): void
    {
        // Moved with the rest of prompt construction into AskPromptBuilder,
        // so the eval harness scores the same prompt the product sends.
        $builder = app(AskPromptBuilder::class);
        $method = new \ReflectionMethod($builder, 'academicYear');

        $this->assertSame('2026-2027', $method->invoke($builder, new \DateTimeImmutable('2026-09-18')));
        $this->assertSame('2026-2027', $method->invoke($builder, new \DateTimeImmutable('2026-09-01')));
        $this->assertSame('2025-2026', $method->invoke($builder, new \DateTimeImmutable('2026-08-31')));
        $this->assertSame('2025-2026', $method->invoke($builder, new \DateTimeImmutable('2026-03-01')));
    }

    /** Numbers absent from the sources must never be produced. */
    public function test_the_prompt_forbids_inventing_numbers(): void
    {
        Http::fake(['api.groq.com/*' => Http::response([
            'choices' => [['message' => ['content' => 'ok']]],
        ], 200)]);

        $this->postJson('/api/v1/ai/query', ['prompt' => 'taban puanlar?'])->assertOk();

        Http::assertSent(function ($request) {
            $system = $request->data()['messages'][0]['content'];

            return str_contains($system, 'SAYI UYDURMA')
                && str_contains($system, 'taban puan');
        });
    }
}
