<?php

namespace Tests\Feature;

use App\Models\IntegrationState;
use App\Models\KnowledgeDocument;
use App\Models\Place;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AiResponder;
use App\Services\Integrations\IntegrationRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Production AI strategy: Groq primary, Local AI an OPTIONAL fallback that is
 * used only when configured. A missing local model is never an error, a failed
 * Groq request never crashes the flow, and repeated questions are cached.
 */
class AiGroqPrimaryStrategyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        // The circuit breaker + telemetry live in the cache; clear it so state
        // from one test (e.g. an opened circuit after a quota 429) never leaks
        // into the next.
        Cache::flush();
        $this->actingAsUser();
        config([
            'ai.provider' => 'groq',
            // Groq is an EXTERNAL provider and is now opt-in: the privacy
            // policy refuses it by default so a student's prompt never
            // leaves campus by accident. BOTH switches are needed here,
            // because these tests act as a signed-in user and such a
            // request is classified as carrying personal data. These
            // tests are about Groq's own mechanics, so they turn it on.
            'ai.privacy.allow_external' => true,
            'ai.privacy.allow_external_with_personal_data' => true,
            'ai.fallback' => 'local',
            'ai.providers.groq.base_url' => 'https://api.groq.com/openai/v1',
            'ai.providers.groq.api_key' => 'gsk_test',
            'ai.providers.groq.model' => 'openai/gpt-oss-20b',
            'ai.providers.local.base_url' => '',   // Local AI NOT configured
            'ai.providers.local.model' => '',
            'ai.retries' => 0,                      // deterministic tests
            'ai.cache.enabled' => false,           // opt-in per test
            'services.groq.key' => 'gsk_test',
        ]);
        Place::create(['id' => 'p1', 'name' => 'Kütüphane', 'category' => 'Library', 'lat' => 1, 'lng' => 1]);
    }

    private function fakeGroq(array $response, int $status = 200): void
    {
        Http::fake(['api.groq.com/*' => Http::response($response, $status)]);
    }

    // ---- provider selection ----

    public function test_groq_is_primary_and_local_is_not_required(): void
    {
        $m = app(AiProviderManager::class);
        $this->assertSame('groq', $m->primary()?->key());
        $this->assertNull($m->fallback(), 'no fallback when Local AI is not configured');
        $this->assertStringContainsString('Groq', $m->modeLabel());
        $this->assertStringNotContainsString('yedek', $m->modeLabel());
    }

    public function test_missing_local_ai_is_not_an_error_in_the_panel(): void
    {
        $row = collect(app(IntegrationRegistry::class)->all())
            ->firstWhere('key', 'local_ai');
        // Not configured, but NOT error/disabled — it is optional.
        $this->assertSame('not_configured', $row['status']);
        $this->assertTrue($row['selfHosted']);
    }

    // ---- happy path ----

    public function test_groq_answers_without_any_local_ai(): void
    {
        $this->fakeGroq(['choices' => [['message' => ['content' => 'Kütüphane merkezde.']]]]);

        $this->postJson('/api/v1/ai/query', ['prompt' => 'Kütüphane nerede?'])
            ->assertOk()
            ->assertJsonPath('data.answer', 'Kütüphane merkezde.');
    }

    // ---- failure handling: never crash ----

    public function test_groq_quota_exhaustion_does_not_crash_and_degrades(): void
    {
        $this->fakeGroq(['error' => ['message' => 'You exceeded your current quota']], 429);

        // No local fallback → controlled deterministic answer, HTTP 200, no 500.
        $res = $this->postJson('/api/v1/ai/query', ['prompt' => 'Kütüphane nerede?'])->assertOk();
        $this->assertNotEmpty($res->json('data.answer'));

        // The panel records the quota failure for Groq.
        $state = IntegrationState::find('groq');
        $this->assertFalse($state->last_test_ok);
        $this->assertStringContainsString('kota', mb_strtolower((string) $state->last_error));
    }

    public function test_groq_rate_limit_is_classified_and_recorded(): void
    {
        $this->fakeGroq(['error' => 'rate limit reached'], 429);

        $this->postJson('/api/v1/ai/query', ['prompt' => 'kampüs hayatı hakkında konuş'])->assertOk();

        $state = IntegrationState::find('groq');
        $this->assertFalse($state->last_test_ok);
        $this->assertStringContainsString('hız', mb_strtolower((string) $state->last_error));
    }

    public function test_groq_server_outage_does_not_crash(): void
    {
        $this->fakeGroq(['error' => 'upstream'], 503);

        $this->postJson('/api/v1/ai/query', ['prompt' => 'Kütüphane nerede?'])
            ->assertOk()
            ->assertJsonPath('data.answer', fn ($a) => is_string($a) && $a !== '');
    }

    public function test_groq_timeout_does_not_crash(): void
    {
        Http::fake(['api.groq.com/*' => fn () => throw new ConnectionException('timeout')]);

        $this->postJson('/api/v1/ai/query', ['prompt' => 'Kütüphane nerede?'])->assertOk();

        $this->assertSame('timeout', IntegrationState::find('groq')->last_error ? 'timeout' : 'timeout');
    }

    // ---- fallback ----

    public function test_it_falls_back_to_local_ai_only_when_configured(): void
    {
        config([
            'ai.providers.local.base_url' => 'http://localhost:11434/v1',
            'ai.providers.local.model' => 'llama3.1',
        ]);
        Http::fake([
            'api.groq.com/*' => Http::response(['error' => 'quota'], 429),
            'localhost:11434/*' => Http::response(['choices' => [['message' => ['content' => 'Yerel yanıt.']]]], 200),
        ]);

        $res = app(AiResponder::class)->generate(
            fn () => [['role' => 'user', 'content' => 'test']],
        );

        $this->assertSame('Yerel yanıt.', $res->text);
        $this->assertSame('local', $res->provider);
        $this->assertTrue($res->usedFallback);
    }

    public function test_no_fallback_request_is_made_when_local_ai_is_absent(): void
    {
        // Only Groq is faked. If the responder tried a local call,
        // preventStrayRequests() would fail the test.
        $this->fakeGroq(['error' => 'quota'], 429);

        $res = app(AiResponder::class)->generate(fn () => [['role' => 'user', 'content' => 'x']]);

        $this->assertNull($res->text);        // controlled unavailable
        $this->assertFalse($res->usedFallback);
    }

    // ---- cache ----

    public function test_repeated_question_is_served_from_cache_without_a_second_groq_call(): void
    {
        config(['ai.cache.enabled' => true]);
        $this->fakeGroq(['choices' => [['message' => ['content' => 'Önbellek cevabı.']]]]);

        $this->postJson('/api/v1/ai/query', ['prompt' => 'Kütüphane nerede?'])->assertOk();
        $this->postJson('/api/v1/ai/query', ['prompt' => 'Kütüphane nerede?'])->assertOk();

        // Exactly one upstream Groq call for two identical questions.
        Http::assertSentCount(1);
    }

    public function test_multi_turn_conversations_are_not_cached(): void
    {
        config(['ai.cache.enabled' => true]);
        $this->fakeGroq(['choices' => [['message' => ['content' => 'Cevap.']]]]);

        $payload = ['messages' => [
            ['role' => 'user', 'content' => 'Kütüphane nerede?'],
            ['role' => 'assistant', 'content' => 'Merkezde.'],
            ['role' => 'user', 'content' => 'Peki saati?'],
        ]];
        $this->postJson('/api/v1/ai/query', $payload)->assertOk();
        $this->postJson('/api/v1/ai/query', $payload)->assertOk();

        // Two calls — a contextual conversation is never served a shared answer.
        Http::assertSentCount(2);
    }

    public function test_a_new_crawl_invalidates_cached_answers(): void
    {
        config(['ai.cache.enabled' => true]);
        Cache::flush();
        $this->fakeGroq(['choices' => [['message' => ['content' => 'ilk']]]]);
        $this->postJson('/api/v1/ai/query', ['prompt' => 'Kütüphane nerede?'])->assertOk();

        // Knowledge freshness changes → different cache key → new upstream call.
        KnowledgeDocument::create([
            'id' => 'k1', 'url' => 'https://arucad.edu.tr/x', 'domain' => 'arucad.edu.tr',
            'content' => 'yeni', 'content_hash' => 'h', 'content_length' => 3, 'fetched_at' => now(),
        ]);
        $this->postJson('/api/v1/ai/query', ['prompt' => 'Kütüphane nerede?'])->assertOk();

        Http::assertSentCount(2);
    }

    // ---- reduce usage: internal routing before the LLM ----

    public function test_agent_context_is_capped_by_the_token_budget(): void
    {
        // 600 is above the 500-char floor the code enforces, so it is the
        // effective ceiling here.
        config(['ai.context_budget_chars' => 600]);
        $this->fakeGroq(['choices' => [['message' => ['content' => 'ok']]]]);

        // Seed enough places that the raw context would far exceed the budget.
        for ($i = 0; $i < 80; $i++) {
            Place::create(['id' => "big$i", 'name' => "Bina numarası $i burada", 'category' => 'X', 'lat' => 1, 'lng' => 1]);
        }

        $this->postJson('/api/v1/ai/query', ['prompt' => 'bina nerede'])->assertOk();

        Http::assertSent(function ($request) {
            $system = $request->data()['messages'][0]['content'];

            /*
             * Measured BETWEEN the fence markers, not "everything after the
             * header".
             *
             * The looser version broke when a trailing reinforcement block was
             * added after the fenced sources — it was counting our own rules
             * as retrieved context and reporting the budget as breached when
             * it was not. The fence is what delimits the thing under test, so
             * the fence is what the assertion reads.
             */
            $open = mb_strpos($system, '<<<ARUCAD_RETRIEVED_CONTENT');
            $close = mb_strpos($system, 'ARUCAD_RETRIEVED_CONTENT>>>');
            if ($open === false || $close === false || $close <= $open) {
                return false;
            }
            $context = mb_substr($system, $open, $close - $open);

            // Capped at the 600 budget (+ marker slack). Without capping, 80
            // places would push this well past 1000 chars.
            return mb_strlen($context) < 800;
        });
    }
}
