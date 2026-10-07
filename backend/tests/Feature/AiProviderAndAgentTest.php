<?php

namespace Tests\Feature;

use App\Models\FoodVenue;
use App\Models\KnowledgeDocument;
use App\Models\Place;
use App\Services\Agent\AruverseAgent;
use App\Services\Ai\AiProviderManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The AI provider abstraction (local-first, Groq optional) and the controlled
 * ARUVERSE Agent. Network is faked throughout.
 */
class AiProviderAndAgentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        // Groq-primary production strategy.
        config([
            'ai.provider' => 'groq',
            'ai.fallback' => 'local',
            'ai.providers.local.base_url' => '',
            'ai.providers.local.api_key' => '',
            'ai.providers.groq.base_url' => 'https://api.groq.com/openai/v1',
            'ai.providers.groq.api_key' => '',
        ]);
    }

    public function test_no_provider_is_active_when_nothing_is_configured(): void
    {
        $this->assertNull(app(AiProviderManager::class)->active());
        $this->assertFalse(app(AiProviderManager::class)->hasSelfHosted());
    }

    public function test_groq_is_primary_and_local_is_the_fallback_when_both_configured(): void
    {
        config([
            'ai.providers.local.base_url' => 'http://localhost:11434/v1',
            'ai.providers.local.model' => 'llama3.1',
            'ai.providers.groq.api_key' => 'gsk_key',
        ]);

        $m = app(AiProviderManager::class);
        $this->assertSame('groq', $m->primary()?->key());   // Groq is primary
        $this->assertSame('local', $m->fallback()?->key());  // Local is fallback
        $this->assertTrue($m->hasSelfHosted());
    }

    public function test_groq_is_the_provider_when_no_local_is_configured(): void
    {
        config(['ai.providers.groq.api_key' => 'gsk_key']);

        $this->assertSame('groq', app(AiProviderManager::class)->active()->key());
        $this->assertNull(app(AiProviderManager::class)->fallback());
    }

    public function test_local_provider_needs_no_key_but_groq_does(): void
    {
        config(['ai.providers.local.base_url' => 'http://localhost:11434/v1']);
        $this->assertTrue(app(AiProviderManager::class)->get('local')->isConfigured());

        // Groq with a base URL but no key is NOT configured.
        $this->assertFalse(app(AiProviderManager::class)->get('groq')->isConfigured());
    }

    public function test_local_completion_posts_openai_compatible_and_returns_text(): void
    {
        config([
            'ai.provider' => 'local',
            'ai.providers.local.base_url' => 'http://localhost:11434/v1',
            'ai.providers.local.model' => 'llama3.1',
        ]);
        Http::fake([
            'localhost:11434/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Yerel model cevabı.']]],
            ], 200),
        ]);

        $answer = app(AiProviderManager::class)->active()->complete([
            ['role' => 'user', 'content' => 'Merhaba'],
        ]);

        $this->assertSame('Yerel model cevabı.', $answer);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'localhost:11434')
            && ($r->data()['model'] ?? '') === 'llama3.1');
    }

    public function test_ai_query_uses_the_local_provider_when_configured(): void
    {
        config([
            'ai.provider' => 'local',
            'ai.providers.local.base_url' => 'http://localhost:11434/v1',
            'services.groq.key' => '',
        ]);
        $this->actingAsUser();
        Place::create(['id' => 'p1', 'name' => 'Kütüphane', 'category' => 'Library', 'lat' => 1, 'lng' => 1]);
        Http::fake([
            'localhost:11434/*' => Http::response([
                'choices' => [['message' => ['content' => 'Kütüphane merkezde.']]],
            ], 200),
            // If it ever hit Groq, preventStrayRequests would fail the test.
        ]);

        $this->postJson('/api/v1/ai/query', ['prompt' => 'Kütüphane nerede?'])
            ->assertOk()
            ->assertJsonPath('data.answer', 'Kütüphane merkezde.');
    }

    // ---- Agent ----

    public function test_agent_routes_a_food_question_to_the_food_tool(): void
    {
        FoodVenue::create(['id' => 'fv1', 'name' => 'Ana Kafeterya', 'hours' => '08-17']);
        $ctx = app(AruverseAgent::class)->buildContext('Bugün kafeteryada ne yemek var?');
        $this->assertContains('food', $ctx['tools']);
        // A food question should not drag in unrelated career data.
        $this->assertNotContains('career', $ctx['tools']);
    }

    public function test_agent_includes_web_knowledge_with_sources_when_relevant(): void
    {
        KnowledgeDocument::create([
            'id' => KnowledgeDocument::idForUrl('https://aday.arucad.edu.tr/burs-ve-indirimler/'),
            'url' => 'https://aday.arucad.edu.tr/burs-ve-indirimler/',
            'domain' => 'aday.arucad.edu.tr', 'title' => 'Burslar',
            'content' => 'ARUCAD burs ve indirim imkânları sunar. Burs oranları değişir.',
            'content_hash' => 'a', 'content_length' => 60, 'fetched_at' => now(),
        ]);

        $ctx = app(AruverseAgent::class)->buildContext('burs var mı');
        $this->assertTrue($ctx['hasWebSources']);
        $this->assertStringContainsString('Kaynak: https://aday.arucad.edu.tr/burs-ve-indirimler/', $ctx['context']);
    }

    public function test_agent_falls_back_to_campus_basics_for_a_vague_question(): void
    {
        Place::create(['id' => 'p1', 'name' => 'Rodin', 'category' => 'Admin', 'lat' => 1, 'lng' => 1]);
        $ctx = app(AruverseAgent::class)->buildContext('selam');
        // No strong keyword match → default set includes places.
        $this->assertContains('places', $ctx['tools']);
    }

    public function test_agent_tool_table_is_fixed_and_explicit(): void
    {
        // The set of tools is explicit and restricted — this pins it so a new
        // capability cannot be added to the agent without a deliberate test
        // change and review.
        $keys = array_keys(app(AruverseAgent::class)->tools());
        sort($keys);

        // Deliberately widened: AICAD was given the rest of the app's
        // INSTITUTIONAL knowledge - the campus directory, staff contacts, the
        // academic calendar and published consultation offerings - because it
        // was inventing offices and e-mail addresses it could have looked up.
        // Private data (chats, messages, stories, feed posts, applications,
        // moderation records, user profiles) is deliberately still absent and
        // must stay that way: anyone can query this assistant.
        //
        // `programs` (2026-10-04): public programme facts — language of
        // instruction and duration — extracted with provenance from the
        // admissions sites' own pages (knowledge_facts). Institutional and
        // public, like the rest of this list.
        $this->assertSame(
            ['calendar', 'career', 'clubs', 'consultation', 'directory', 'events', 'food',
                'knowledge', 'places', 'programs', 'services', 'shuttle', 'sports', 'staff', 'support'],
            $keys,
        );
    }
}
