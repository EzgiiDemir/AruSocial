<?php

namespace Tests\Feature;

use App\Models\Place;
use App\Services\Ai\AiPrivacy;
use App\Services\Ai\AiProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The local model is primary, and a student's prompt does not leave campus.
 *
 * The failure this guards against is the quiet one: the GPU host goes down
 * at 2am, the fallback chain does its job, and every question for the next
 * six hours is posted to a third-party API with the asker's own data in it
 * — with nothing in the product saying so.
 */
class LocalAiPrimaryTest extends TestCase
{
    use RefreshDatabase;

    private const LOCAL = 'http://10.0.0.12:8000/v1';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Cache::flush();
        $this->actingAsUser();
        config([
            'ai.provider' => 'local',
            'ai.fallback' => 'groq',
            'ai.providers.local.base_url' => self::LOCAL,
            'ai.providers.local.model' => 'arucad-ask',
            'ai.providers.local.api_key' => 'vllm-secret',
            'ai.providers.groq.base_url' => 'https://api.groq.com/openai/v1',
            'ai.providers.groq.api_key' => 'gsk_test',
            'ai.retries' => 0,
            'ai.cache.enabled' => false,
            'ai.allow_knowledge_only_fallback' => true,
            // The shipped defaults, stated so the test documents them.
            'ai.privacy.allow_external' => false,
            'ai.privacy.allow_external_with_personal_data' => false,
        ]);
        Place::create(['id' => 'p1', 'name' => 'Kütüphane', 'category' => 'Library', 'lat' => 1, 'lng' => 1]);
    }

    private function localAnswers(string $text = 'Kütüphane kampüsün merkezinde.'): void
    {
        Http::fake([
            '10.0.0.12:8000/*' => Http::response([
                'choices' => [['message' => ['content' => $text]]],
                'usage' => ['prompt_tokens' => 800, 'completion_tokens' => 40],
            ], 200),
        ]);
    }

    private function ask(string $prompt = 'kütüphane nerede'): array
    {
        return $this->postJson('/api/v1/ai/query', ['prompt' => $prompt])
            ->assertOk()->json('data');
    }

    public function test_the_local_model_answers_and_groq_is_never_called(): void
    {
        $this->localAnswers();

        $data = $this->ask('kütüphane hakkında ne biliyorsun');

        $this->assertSame('local', $data['aiMode']);
        Http::assertSent(fn ($r) => str_contains($r->url(), '10.0.0.12:8000'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'api.groq.com'));
    }

    public function test_the_local_endpoint_receives_the_configured_model_and_bearer(): void
    {
        $this->localAnswers();

        $this->ask('kütüphane hakkında ne biliyorsun');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '10.0.0.12:8000')
                && $request->data()['model'] === 'arucad-ask'
                && $request->hasHeader('Authorization', 'Bearer vllm-secret');
        });
    }

    /**
     * The point of the whole arrangement: local down does NOT mean
     * "post it to Groq instead".
     */
    public function test_a_local_outage_degrades_to_knowledge_only_rather_than_calling_groq(): void
    {
        Http::fake([
            '10.0.0.12:8000/*' => Http::response('', 500),
            'api.groq.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'groq answered']]],
            ], 200),
        ]);

        $data = $this->ask('kütüphane hakkında ne biliyorsun');

        $this->assertSame('knowledge_only', $data['aiMode']);
        $this->assertStringNotContainsString('groq answered', $data['answer']);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'api.groq.com'));
    }

    public function test_enabling_both_switches_lets_groq_take_over_again(): void
    {
        config([
            'ai.privacy.allow_external' => true,
            'ai.privacy.allow_external_with_personal_data' => true,
        ]);
        Http::fake([
            '10.0.0.12:8000/*' => Http::response('', 500),
            'api.groq.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'Kütüphane merkezde.']]],
            ], 200),
        ]);

        $data = $this->ask('kütüphane hakkında ne biliyorsun');

        $this->assertSame('groq', $data['aiMode']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.groq.com'));
    }

    /** One switch is not enough when personal context is actually requested. */
    public function test_allowing_external_alone_still_keeps_personal_prompts_at_home(): void
    {
        config(['ai.privacy.allow_external' => true]);
        Http::fake([
            '10.0.0.12:8000/*' => Http::response('', 500),
            'api.groq.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'groq answered']]],
            ], 200),
        ]);

        $data = $this->ask('Bölümüm ve sınıf seviyeme göre bana kişisel öneri ver');

        $this->assertSame('knowledge_only', $data['aiMode']);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'api.groq.com'));
    }

    public function test_the_privacy_policy_is_decided_per_provider_not_per_request_shape(): void
    {
        $manager = app(AiProviderManager::class);
        $local = $manager->get('local');
        $groq = $manager->get('groq');

        // Self-hosted is always allowed, whatever the switches say.
        $this->assertTrue(AiPrivacy::personal()->allows($local));
        $this->assertTrue(AiPrivacy::public()->allows($local));

        $this->assertFalse(AiPrivacy::public()->allows($groq));
        $this->assertFalse(AiPrivacy::personal()->allows($groq));
        $this->assertSame('external_providers_disabled', AiPrivacy::public()->refusalReason());

        config(['ai.privacy.allow_external' => true]);
        $this->assertTrue(AiPrivacy::public()->allows($groq));
        $this->assertFalse(AiPrivacy::personal()->allows($groq));
        $this->assertSame('personal_data_stays_local', AiPrivacy::personal()->refusalReason());
    }

    public function test_generation_settings_are_configurable_and_sent(): void
    {
        config([
            'ai.temperature' => 0.2,
            'ai.max_tokens' => 800,
            'ai.providers.local.temperature' => 0.15,
            'ai.providers.local.max_tokens' => 512,
            'ai.providers.local.reasoning_effort' => 'none',
        ]);
        $this->localAnswers();

        $this->ask('kütüphane hakkında ne biliyorsun');

        Http::assertSent(function ($request) {
            $body = $request->data();

            // The per-provider override wins over the shared default.
            return abs($body['temperature'] - 0.15) < 0.0001
                && $body['max_tokens'] === 512
                && $body['reasoning_effort'] === 'none';
        });
    }

    public function test_without_a_provider_override_the_shared_defaults_apply(): void
    {
        config([
            'ai.temperature' => 0.25,
            'ai.max_tokens' => 600,
            'ai.providers.local.temperature' => null,
            'ai.providers.local.max_tokens' => null,
        ]);
        $this->localAnswers();

        $this->ask('kütüphane hakkında ne biliyorsun');

        Http::assertSent(function ($request) {
            $body = $request->data();

            return abs($body['temperature'] - 0.25) < 0.0001 && $body['max_tokens'] === 600;
        });
    }

    public function test_health_reports_the_local_model_without_leaking_the_key(): void
    {
        Http::fake(['10.0.0.12:8000/*' => Http::response(['data' => []], 200)]);
        $this->actingAsRole('superAdmin');

        $model = $this->getJson('/api/v1/admin/system-health')
            ->assertOk()->json('data.assistant.model');

        $this->assertSame('local', $model['provider']);
        $this->assertTrue($model['selfHosted']);
        $this->assertSame('arucad-ask', $model['name']);
        $this->assertSame('http://10.0.0.12:8000', $model['endpoint']);
        $this->assertTrue($model['reachable']);
        $this->assertIsInt($model['latencyMs']);
        $this->assertFalse($model['externalAllowed']);

        // The bearer token is not a status field.
        $body = $this->getJson('/api/v1/admin/system-health')->getContent();
        $this->assertStringNotContainsString('vllm-secret', $body);
    }

    public function test_health_says_so_when_the_local_model_is_unreachable(): void
    {
        Http::fake(['10.0.0.12:8000/*' => Http::response('', 503)]);
        $this->actingAsRole('superAdmin');

        $model = $this->getJson('/api/v1/admin/system-health')
            ->assertOk()->json('data.assistant.model');

        $this->assertFalse($model['reachable']);
        $this->assertNotEmpty($model['detail']);
    }
}
