<?php

namespace Tests\Feature;

use App\Models\Place;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAsUser();
    }

    protected function tearDown(): void
    {
        config(['services.groq.key' => null]);
        unset($_ENV['GROQ_API_KEY'], $_SERVER['GROQ_API_KEY']);
        parent::tearDown();
    }

    private function withGroqKey(string $key = 'test-key'): void
    {
        config(['services.groq.key' => $key]);
        $_ENV['GROQ_API_KEY'] = $key;
        $_SERVER['GROQ_API_KEY'] = $key;
    }

    public function test_without_a_configured_key_it_answers_from_campus_catalog(): void
    {
        config(['services.groq.key' => '']);
        Place::create(['id' => 'p1', 'name' => 'Kütüphane', 'category' => 'Library', 'lat' => 1, 'lng' => 1]);

        $response = $this->postJson('/api/v1/ai/query', ['prompt' => 'Kütüphane nerede?']);

        $response->assertOk();
        $this->assertStringContainsString('Kütüphane', (string) $response->json('data.answer'));
    }

    public function test_a_configured_key_sends_a_real_system_prompt_with_live_campus_data(): void
    {
        $this->withGroqKey();
        Place::create(['id' => 'p1', 'name' => 'Kütüphane', 'category' => 'Library', 'lat' => 1, 'lng' => 1]);
        Http::fake([
            'api.groq.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'Kütüphane kampüsün merkezinde.']]],
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/ai/query', ['prompt' => 'Kütüphane nerede?']);

        $response->assertOk();
        $this->assertSame('Kütüphane kampüsün merkezinde.', $response->json('data.answer'));
        Http::assertSent(function ($request) {
            $messages = $request->data()['messages'];
            $system = $messages[0]['content'];

            return $messages[0]['role'] === 'system'
                && ($request->data()['model'] ?? '') === 'openai/gpt-oss-20b'
                && str_contains($system, 'Kütüphane')
                && str_contains($system, 'Markdown biçimlendirmesi KULLANMA');
        });
    }

    public function test_full_conversation_history_is_forwarded_to_groq_for_real_multi_turn_context(): void
    {
        $this->withGroqKey();
        Http::fake([
            'api.groq.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'Cevap.']]],
            ], 200),
        ]);

        $this->postJson('/api/v1/ai/query', [
            'messages' => [
                ['role' => 'user', 'content' => 'Kütüphane saat kaçta açılıyor?'],
                ['role' => 'assistant', 'content' => '09:00\'da açılıyor.'],
                ['role' => 'user', 'content' => 'Peki kapanış?'],
            ],
        ])->assertOk();

        Http::assertSent(function ($request) {
            $messages = $request->data()['messages'];

            // system + the 3 forwarded turns.
            return count($messages) === 4
                && $messages[1]['content'] === 'Kütüphane saat kaçta açılıyor?'
                && $messages[2]['role'] === 'assistant'
                && $messages[3]['content'] === 'Peki kapanış?';
        });
    }

    public function test_stray_markdown_in_the_model_response_is_stripped_before_it_reaches_the_client(): void
    {
        $this->withGroqKey();
        Http::fake([
            'api.groq.com/*' => Http::response([
                'choices' => [['message' => ['content' => "**Kütüphane** şurada:\n- Ana bina\n# Not"]]],
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/ai/query', ['prompt' => 'Kütüphane nerede?']);

        $answer = $response->json('data.answer');
        $this->assertStringNotContainsString('**', $answer);
        $this->assertStringNotContainsString('#', $answer);
        $this->assertStringContainsString('Kütüphane şurada', $answer);
    }

    public function test_an_upstream_groq_failure_falls_back_to_the_campus_catalog(): void
    {
        $this->withGroqKey();
        Place::create(['id' => 'p1', 'name' => 'Kütüphane', 'category' => 'Library', 'lat' => 1, 'lng' => 1]);
        Http::fake([
            'api.groq.com/*' => Http::response(['error' => ['message' => 'rate limited']], 429),
        ]);

        $response = $this->postJson('/api/v1/ai/query', ['prompt' => 'Kütüphane nerede?']);

        $response->assertOk();
        $this->assertStringContainsString('Kütüphane', (string) $response->json('data.answer'));
    }

    public function test_empty_content_falls_back_to_reasoning_text_so_the_client_is_not_blank(): void
    {
        $this->withGroqKey();
        Http::fake([
            'api.groq.com/*' => Http::response([
                'choices' => [['message' => [
                    'content' => '',
                    'reasoning' => 'Kütüphane Meditation binasında.',
                ]]],
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/ai/query', ['prompt' => 'Kütüphane nerede?']);

        $response->assertOk();
        $this->assertSame('Kütüphane Meditation binasında.', $response->json('data.answer'));
    }
}
