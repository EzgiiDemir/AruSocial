<?php

namespace Tests\Feature;

use App\Models\KnowledgeDocument;
use App\Models\Place;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * What comes OUT: the citations the API reports, and what the logs keep.
 *
 * Citations are taken from what the backend put in the prompt, never from
 * the answer text — a model that invents a URL invents a citation with it,
 * so a list scraped from the reply would be exactly as unreliable as the
 * reply.
 */
class AskSourcesAndLoggingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Cache::flush();
        config([
            'ai.provider' => 'local',
            'ai.fallback' => '',
            'ai.providers.local.base_url' => 'http://local.test/v1',
            'ai.providers.local.model' => 'arucad-ask',
            'ai.providers.local.api_key' => 'vllm-secret',
            'ai.cache.enabled' => false,
            'ai.retries' => 0,
            'knowledge.on_demand_enabled' => false,
            'knowledge.embeddings.enabled' => false,
        ]);
        Place::create(['id' => 'p1', 'name' => 'Kütüphane', 'category' => 'Library', 'lat' => 1, 'lng' => 1]);
    }

    private function indexBurslar(): void
    {
        KnowledgeDocument::create([
            'id' => sha1('https://arucad.edu.tr/burslar/'),
            'url' => 'https://arucad.edu.tr/burslar/',
            'domain' => 'arucad.edu.tr',
            'title' => 'Burslar ve Ücretler',
            'content' => 'Burs oranlari ve basvuru kosullari bu sayfada.',
            'content_hash' => sha1('burslar'),
            'content_length' => 46,
            'fetched_at' => now(),
            'is_stale' => false,
        ]);
    }

    private function modelAnswers(string $text = 'Burs bilgileri sayfada.'): void
    {
        Http::fake(['local.test/*' => Http::response([
            'choices' => [['message' => ['content' => $text]]],
            'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 30],
        ], 200)]);
    }

    public function test_the_answer_carries_the_sources_the_backend_supplied(): void
    {
        $this->actingAsUser();
        $this->indexBurslar();
        $this->modelAnswers();

        $data = $this->postJson('/api/v1/ai/query', ['prompt' => 'başvuru koşulları hakkında ayrıntılı bilgi ver'])
            ->assertOk()->json('data');

        $this->assertArrayHasKey('sources', $data);
        $web = array_values(array_filter($data['sources'], fn ($s) => $s['type'] === 'web'));

        $this->assertNotEmpty($web, 'An indexed page was used and must be cited.');
        $this->assertSame('https://arucad.edu.tr/burslar/', $web[0]['url']);
        $this->assertSame('Burslar ve Ücretler', $web[0]['title']);
        $this->assertNotEmpty($web[0]['id']);
    }

    /**
     * Citations describe what was RETRIEVED, so a model inventing a link
     * cannot smuggle it into the source list.
     */
    public function test_a_url_invented_by_the_model_never_becomes_a_source(): void
    {
        $this->actingAsUser();
        $this->indexBurslar();
        $this->modelAnswers('Detaylar icin https://arucad.edu.tr/uydurma-burs-sayfasi/ adresine bak.');

        $data = $this->postJson('/api/v1/ai/query', ['prompt' => 'başvuru koşulları hakkında ayrıntılı bilgi ver'])
            ->assertOk()->json('data');

        $urls = array_column($data['sources'], 'url');
        $this->assertNotContains('https://arucad.edu.tr/uydurma-burs-sayfasi/', $urls);
    }

    public function test_a_direct_answer_reports_no_sources_rather_than_a_plausible_one(): void
    {
        $this->actingAsUser();
        // DirectAnswer declines a place it only knows the NAME of, so the
        // row needs the fact it would answer with.
        Place::where('id', 'p1')->update(['street' => 'Merkez Kampüs, A Blok']);
        Http::fake();

        $data = $this->postJson('/api/v1/ai/query', ['prompt' => 'Kütüphane nerede?'])
            ->assertOk()->json('data');

        $this->assertSame('direct', $data['aiMode']);
        $this->assertSame([], $data['sources']);
    }

    public function test_internal_campus_tools_are_reported_as_sources_without_a_url(): void
    {
        $this->actingAsUser();
        $this->modelAnswers();

        $data = $this->postJson('/api/v1/ai/query', ['prompt' => 'kampüste hangi yerler var acaba'])
            ->assertOk()->json('data');

        $campus = array_values(array_filter($data['sources'], fn ($s) => $s['type'] === 'campus'));
        $this->assertNotEmpty($campus);
        $this->assertSame('', $campus[0]['url']);
        $this->assertNotEmpty($campus[0]['title']);
    }

    // --------------------------------------------------------- logging

    /**
     * An application log is read by more people, kept longer and shipped
     * further than the database it was derived from. A student's own data
     * has no business in it.
     */
    public function test_completion_logs_carry_operational_metadata_only(): void
    {
        $me = User::firstOrCreate(
            ['email' => 'logged@arucad.edu.tr'],
            ['name' => 'Gizli Ogrenci', 'password' => bcrypt('x'), 'department' => 'Seramik'],
        );
        $this->actingAsUser($me);
        $this->indexBurslar();
        $this->modelAnswers();

        $records = [];
        Log::listen(function ($record) use (&$records) {
            $records[] = $record;
        });

        $this->postJson('/api/v1/ai/query', ['prompt' => 'başvuru koşulları hakkında ayrıntılı bilgi ver'])->assertOk();

        $completion = array_values(array_filter(
            $records, fn ($r) => $r->message === 'ai.completion',
        ));
        $this->assertNotEmpty($completion, 'A completion should be logged.');

        $context = $completion[0]->context;
        $this->assertSame('local', $context['provider']);
        $this->assertSame('arucad-ask', $context['model']);
        $this->assertSame('ok', $context['status']);
        $this->assertIsInt($context['latency_ms']);
        $this->assertSame(900, $context['prompt_tokens']);

        // Nothing private, in any field.
        $flat = json_encode($context, JSON_UNESCAPED_UNICODE);
        foreach ([
            'başvuru koşulları hakkında ayrıntılı bilgi ver', // the prompt
            'Gizli Ogrenci',             // the student's name
            'Seramik',                   // their department
            'vllm-secret',               // the bearer token
            'Burs bilgileri sayfada.',   // the answer
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $flat,
                "Log context must not contain: {$secret}");
        }
    }

    public function test_a_privacy_refusal_is_logged_with_its_reason_and_no_prompt(): void
    {
        config([
            'ai.provider' => 'groq',
            'ai.fallback' => '',
            'ai.providers.groq.api_key' => 'gsk_test',
            'ai.privacy.allow_external' => false,
        ]);
        $this->actingAsUser();
        Http::fake();

        $records = [];
        Log::listen(function ($record) use (&$records) {
            $records[] = $record;
        });

        $this->postJson('/api/v1/ai/query', ['prompt' => 'kampüs yaşamı hakkında detaylı bilgi ver'])->assertOk();

        $refusals = array_values(array_filter(
            $records, fn ($r) => $r->message === 'ai.provider.refused_by_privacy_policy',
        ));
        $this->assertNotEmpty($refusals);
        $this->assertSame('groq', $refusals[0]->context['provider']);
        $this->assertSame('external_providers_disabled', $refusals[0]->context['reason']);
        $this->assertStringNotContainsString(
            'kampüs yaşamı hakkında detaylı bilgi ver',
            json_encode($refusals[0]->context, JSON_UNESCAPED_UNICODE),
        );
        Http::assertNothingSent();
    }
}
