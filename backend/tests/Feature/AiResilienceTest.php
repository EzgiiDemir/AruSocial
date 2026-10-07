<?php

namespace Tests\Feature;

use App\Models\Place;
use App\Models\KnowledgeDocument;
use App\Services\Ai\AiCircuitBreaker;
use App\Services\Ai\AiCompletion;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AiResponder;
use App\Services\Ai\AiTelemetry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Resilience: ARUVERSE keeps working when Groq cannot serve — circuit breaker,
 * Local-AI emergency fallback, and Knowledge-Only degraded mode. The system
 * must never crash solely because an AI provider is unavailable.
 */
class AiResilienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->actingAsUser();
        Cache::flush();
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
            'ai.providers.local.base_url' => '',
            'ai.providers.local.model' => '',
            'ai.retries' => 0,
            'ai.cache.enabled' => false,
            'ai.allow_knowledge_only_fallback' => true,
            'ai.circuit.failure_threshold' => 2,
            'ai.circuit.cooldown_seconds' => 120,
            'ai.circuit.quota_cooldown_seconds' => 900,
            'services.groq.key' => 'gsk_test',
        ]);
        Place::create(['id' => 'p1', 'name' => 'Kütüphane', 'category' => 'Library', 'lat' => 1, 'lng' => 1]);
    }

    private function build(): callable
    {
        return fn () => [['role' => 'user', 'content' => 'Kütüphane nerede?']];
    }

    // ---- circuit breaker ----

    public function test_quota_error_opens_the_circuit_immediately(): void
    {
        Http::fake(['api.groq.com/*' => Http::response(['error' => 'You exceeded your quota'], 429)]);

        app(AiResponder::class)->generate($this->build());

        $this->assertTrue(app(AiCircuitBreaker::class)->isOpen());
    }

    public function test_transient_failures_open_the_circuit_after_the_threshold(): void
    {
        Http::fake(['api.groq.com/*' => Http::response(['error' => 'upstream'], 503)]);
        $breaker = app(AiCircuitBreaker::class);

        app(AiResponder::class)->generate($this->build());
        $this->assertFalse($breaker->isOpen(), 'one failure should not open it (threshold 2)');

        app(AiResponder::class)->generate($this->build());
        $this->assertTrue($breaker->isOpen(), 'second failure reaches the threshold');
    }

    public function test_open_circuit_skips_groq_entirely(): void
    {
        // Force the circuit open, then assert NO Groq call is made.
        app(AiCircuitBreaker::class)->recordFailure(AiCompletion::QUOTA);
        Http::fake(); // any request would be a stray → test failure

        $res = app(AiResponder::class)->generate($this->build());

        Http::assertNothingSent();
        $this->assertTrue($res->knowledgeOnly);
    }

    public function test_a_successful_call_closes_the_circuit(): void
    {
        app(AiCircuitBreaker::class)->recordFailure(AiCompletion::ERROR);
        Http::fake(['api.groq.com/*' => Http::response(['choices' => [['message' => ['content' => 'ok']]]], 200)]);

        app(AiResponder::class)->generate($this->build());

        $this->assertFalse(app(AiCircuitBreaker::class)->isOpen());
    }

    // ---- knowledge-only degraded mode ----

    public function test_knowledge_only_mode_when_no_provider_can_serve(): void
    {
        Http::fake(['api.groq.com/*' => Http::response(['error' => 'quota'], 429)]);

        $res = app(AiResponder::class)->generate($this->build());

        $this->assertNull($res->text);
        $this->assertTrue($res->knowledgeOnly);
        $this->assertSame('knowledge_only', $res->status);
    }

    public function test_api_returns_a_labelled_source_based_answer_in_knowledge_only_mode(): void
    {
        Http::fake(['api.groq.com/*' => Http::response(['error' => 'quota'], 429)]);

        $res = $this->postJson('/api/v1/ai/query', ['prompt' => 'Kütüphane nerede?'])->assertOk();

        $this->assertSame('knowledge_only', $res->json('data.aiMode'));
        // Grounded internal answer, explicitly marked as source-based.
        $this->assertStringContainsString('ARUCAD kaynaklar', (string) $res->json('data.answer'));
        $this->assertNotEmpty($res->json('data.answer'));
    }

    public function test_knowledge_only_answer_uses_the_crawled_page_not_only_its_link(): void
    {
        KnowledgeDocument::create([
            'id' => KnowledgeDocument::idForUrl('https://arucad.edu.tr/lisans-akademik-takvim/'),
            'url' => 'https://arucad.edu.tr/lisans-akademik-takvim/',
            'domain' => 'arucad.edu.tr',
            'title' => 'Lisans Akademik Takvim',
            'content' => '2026-2027 Güz Dönemi Ders Başlangıcı 28 Eylül 2026. Geç kayıt başlangıcı aynı gündür.',
            'content_hash' => 'calendar',
            'content_length' => 91,
            'language' => 'tr',
            'fetched_at' => now(),
        ]);
        Http::fake(['api.groq.com/*' => Http::response(['error' => 'quota'], 429)]);

        $res = $this->postJson('/api/v1/ai/query', [
            'prompt' => 'Bu yıl dersler ne zaman başlıyor?',
        ])->assertOk();

        $answer = (string) $res->json('data.answer');
        $this->assertSame('knowledge_only', $res->json('data.aiMode'));
        $this->assertStringContainsString('28 Eylül 2026', $answer);
        $this->assertStringContainsString('https://arucad.edu.tr/lisans-akademik-takvim/', $answer);
    }

    public function test_knowledge_only_can_be_disabled_for_a_hard_unavailable(): void
    {
        config(['ai.allow_knowledge_only_fallback' => false]);
        Http::fake(['api.groq.com/*' => Http::response(['error' => 'quota'], 429)]);

        $res = app(AiResponder::class)->generate($this->build());
        $this->assertSame('unavailable', $res->status);
        $this->assertFalse($res->knowledgeOnly);
    }

    public function test_system_never_500s_when_groq_is_down(): void
    {
        Http::fake(['api.groq.com/*' => fn () => throw new ConnectionException('down')]);

        $this->postJson('/api/v1/ai/query', ['prompt' => 'Kütüphane nerede?'])
            ->assertOk()
            ->assertJsonPath('data.answer', fn ($a) => is_string($a) && $a !== '');
    }

    // ---- local fallback ----

    public function test_local_ai_serves_when_groq_fails_and_local_is_configured(): void
    {
        config([
            'ai.providers.local.base_url' => 'http://localhost:11434/v1',
            'ai.providers.local.model' => 'llama3.1',
        ]);
        Http::fake([
            'api.groq.com/*' => Http::response(['error' => 'quota'], 429),
            'localhost:11434/*' => Http::response(['choices' => [['message' => ['content' => 'Yerel yanıt.']]]], 200),
        ]);

        $res = app(AiResponder::class)->generate($this->build());

        $this->assertSame('Yerel yanıt.', $res->text);
        $this->assertTrue($res->usedFallback);
    }

    // ---- monitoring ----

    public function test_telemetry_counts_requests_quota_and_knowledge_only(): void
    {
        Http::fake(['api.groq.com/*' => Http::response(['error' => 'You exceeded your quota'], 429)]);

        app(AiResponder::class)->generate($this->build());

        $m = app(AiTelemetry::class)->snapshot();
        $this->assertSame(1, $m['requests']);
        $this->assertSame(1, $m['groq_calls']);
        $this->assertSame(1, $m['groq_failures']);
        $this->assertSame(1, $m['quota_errors']);
        $this->assertSame(1, $m['knowledge_only']);
    }

    public function test_cache_hit_is_counted_and_avoids_groq(): void
    {
        config(['ai.cache.enabled' => true]);
        Http::fake(['api.groq.com/*' => Http::response(['choices' => [['message' => ['content' => 'cevap']]]], 200)]);

        $this->postJson('/api/v1/ai/query', ['prompt' => 'Kütüphane nerede?'])->assertOk();
        $this->postJson('/api/v1/ai/query', ['prompt' => 'Kütüphane nerede?'])->assertOk();

        Http::assertSentCount(1);
        $this->assertGreaterThanOrEqual(1, app(AiTelemetry::class)->snapshot()['cache_hits']);
    }

    // ---- live mode label ----

    public function test_live_mode_reflects_an_open_circuit(): void
    {
        $mgr = app(AiProviderManager::class);
        $this->assertStringContainsString('Groq', $mgr->liveModeLabel());

        app(AiCircuitBreaker::class)->recordFailure(AiCompletion::QUOTA);
        // No local fallback → knowledge-only degraded mode label.
        $this->assertStringContainsString('Bilgi Tabanı', $mgr->liveModeLabel());
    }
}
