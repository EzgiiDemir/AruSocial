<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Event;
use App\Models\KnowledgeDocument;
use App\Models\Place;
use App\Models\ShuttleRoute;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\Agent\AruverseAgent;
use App\Services\Ai\AskPromptBuilder;
use App\Services\Ai\SourceAuthority;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Which source decides a fact.
 *
 * The assistant reads several systems at once and they disagree. A crawled
 * page says an event starts at 14:00 because it said so in March; the
 * events table says 15:00 because someone moved it yesterday. Merging
 * those, or letting the model choose, produces a confident wrong answer.
 */
class SourceAuthorityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Cache::flush();
        config([
            'knowledge.on_demand_enabled' => false,
            'knowledge.embeddings.enabled' => false,
        ]);
    }

    private function indexPage(string $title, string $content, array $extra = []): void
    {
        $url = $extra['url'] ?? 'https://arucad.edu.tr/test/';
        KnowledgeDocument::create(array_merge([
            'id' => sha1($url),
            'url' => $url,
            'domain' => 'arucad.edu.tr',
            'title' => $title,
            'content' => $content,
            'content_hash' => sha1($content.$url),
            'content_length' => mb_strlen($content),
            'fetched_at' => now()->subDays(30),
            'is_stale' => false,
        ], $extra));
    }

    private function liveEvent(string $title, string $time): void
    {
        Event::create([
            'id' => 'e-'.sha1($title), 'title' => $title, 'draft' => false,
            'workflow_status' => 'published', 'time' => $time,
            'place_name' => 'Sahne', 'category' => 'Konser',
            'event_date' => now()->addDay()->toDateString(),
        ]);
    }

    // ------------------------------------------------- precedence ordering

    public function test_live_database_blocks_come_before_crawled_web_text(): void
    {
        $this->liveEvent('Bahar Konseri', '15:00');
        $this->indexPage('Etkinlikler', 'Bahar Konseri saat 14:00 baslayacaktir.');

        $prompt = app(AskPromptBuilder::class)->text('bahar konseri etkinlik saati');

        $dbAt = mb_strpos($prompt, 'Etkinlikler (tarih sırasıyla)');
        $webAt = mb_strpos($prompt, 'ARUCAD resmî kaynaklarından bilgiler');

        $this->assertNotFalse($dbAt, 'The events table must be in the prompt.');
        $this->assertNotFalse($webAt, 'The crawled page must be in the prompt.');
        $this->assertLessThan($webAt, $dbAt,
            'Our own tables must precede a crawled snapshot: the snapshot is '
            .'what a page said when it was last read, the table is what is true now.');
    }

    public function test_the_rules_state_the_precedence_and_the_conflict_rule(): void
    {
        $prompt = app(AskPromptBuilder::class)->text('etkinlik');
        $flat = preg_replace('/\s+/u', ' ', $prompt);

        $this->assertStringContainsString('KAYNAK ÖNCELİĞİ', $flat);
        // The database beats the web on fast-moving facts.
        $this->assertStringContainsString('veritabanı bloğu web alıntısını', $flat);
        // Conversation history is context, never evidence.
        $this->assertStringContainsString('ASLA bir olgunun kaynağı DEĞİLDİR', $flat);
        // Pretrained knowledge is not an authority here.
        $this->assertStringContainsString('KAYNAK YOKSA İDDİA YOK', $flat);
    }

    public function test_a_web_snippet_carries_the_date_it_was_crawled(): void
    {
        $this->indexPage('Burslar', 'Burs bilgisi.', [
            'url' => 'https://arucad.edu.tr/burslar/',
            'fetched_at' => now()->subDays(40),
        ]);

        $prompt = app(AskPromptBuilder::class)->text('burs');

        $this->assertStringContainsString(
            '[site görüntüleme: '.now()->subDays(40)->format('Y-m-d').']',
            $prompt,
            'The model can only prefer a live row over a snapshot if it can see the snapshot is old.',
        );
    }

    public function test_an_unreachable_page_is_labelled_rather_than_presented_as_current(): void
    {
        $this->indexPage('Eski Duyuru', 'Kayit tarihleri degisti.', [
            'url' => 'https://arucad.edu.tr/eski/',
            'is_stale' => true,
        ]);

        $prompt = app(AskPromptBuilder::class)->text('kayit tarihleri duyuru');

        $this->assertStringContainsString('[ARTIK ERİŞİLEMEYEN SAYFA]', $prompt);
    }

    // --------------------------------------------------------- ranking

    public function test_sources_are_ranked_strongest_first(): void
    {
        $ranked = SourceAuthority::rank([
            ['id' => 'w', 'authority' => SourceAuthority::WEB],
            ['id' => 'p', 'authority' => SourceAuthority::PERSONAL],
            ['id' => 'o', 'authority' => SourceAuthority::OPERATIONAL],
        ]);

        $this->assertSame(['p', 'o', 'w'], array_column($ranked, 'id'));
    }

    public function test_a_stale_source_loses_to_a_current_one_of_equal_standing(): void
    {
        $ranked = SourceAuthority::rank([
            ['id' => 'old', 'authority' => SourceAuthority::WEB, 'stale' => true],
            ['id' => 'new', 'authority' => SourceAuthority::WEB, 'stale' => false],
        ]);

        $this->assertSame(['new', 'old'], array_column($ranked, 'id'));
    }

    public function test_model_knowledge_ranks_below_everything_including_conversation(): void
    {
        $this->assertLessThan(SourceAuthority::CONVERSATION, SourceAuthority::MODEL);
        $this->assertLessThan(SourceAuthority::WEB, SourceAuthority::CONVERSATION);
        $this->assertLessThan(SourceAuthority::OPERATIONAL, SourceAuthority::WEB);
        $this->assertLessThan(SourceAuthority::PERSONAL, SourceAuthority::OPERATIONAL);
    }

    // ------------------------------------------------------- citations

    public function test_user_private_data_is_never_citable(): void
    {
        $this->assertFalse(SourceAuthority::isCitable(SourceAuthority::VISIBILITY_USER_PRIVATE));
        $this->assertTrue(SourceAuthority::isCitable(SourceAuthority::VISIBILITY_PUBLIC));
        $this->assertTrue(SourceAuthority::isCitable(SourceAuthority::VISIBILITY_INTERNAL));
    }

    public function test_a_personal_answer_cites_public_sources_only(): void
    {
        $me = User::firstOrCreate(
            ['email' => 'owner@arucad.edu.tr'],
            ['name' => 'Sahibi', 'password' => bcrypt('x'), 'department' => 'Mimarlik'],
        );
        $staff = StaffProfile::create([
            'id' => 'st1', 'name' => 'Danisman Hoca', 'title' => 'Ogr. Gor.',
            'department' => 'Mimarlik',
        ]);
        Appointment::create([
            'id' => 'ap1', 'staff_profile_id' => $staff->id, 'student_user_id' => $me->id,
            'slot_date' => now()->addDay()->toDateString(),
            'start_time' => '15:00', 'end_time' => '15:30', 'status' => 'booked',
        ]);
        $this->liveEvent('Acik Ders', '10:00');
        $this->actingAsUser($me);

        config([
            'ai.provider' => 'local',
            'ai.fallback' => '',
            'ai.providers.local.base_url' => 'http://local.test/v1',
            'ai.providers.local.model' => 'arucad-ask',
            'ai.cache.enabled' => false,
        ]);
        Http::fake(['local.test/*' => Http::response([
            'choices' => [['message' => ['content' => 'Randevun yarin 15:00.']]],
        ], 200)]);

        $data = $this->postJson('/api/v1/ai/query',
            ['prompt' => 'yarinki randevum ve etkinlikler ne durumda'])
            ->assertOk()->json('data');

        // The appointment informed the answer; it is not a source card.
        $titles = implode(' ', array_column($data['sources'], 'title'));
        $this->assertStringNotContainsString('Danisman Hoca', $titles);
        $this->assertStringNotContainsString('15:00', $titles);

        foreach ($data['sources'] as $source) {
            $this->assertNotSame(SourceAuthority::PERSONAL, $source['authority'],
                'A student\'s own data must never be rendered as a citation.');
        }
    }

    public function test_citations_expose_authority_and_freshness_but_not_internals(): void
    {
        $this->indexPage('Burslar ve Ücretler', 'Burs oranlari.', [
            'url' => 'https://arucad.edu.tr/burslar/',
        ]);
        ShuttleRoute::create([
            'id' => 'r1', 'name' => 'Lefkosa', 'color_key' => 'blue',
            'stops' => ['A', 'B'], 'departures' => ['08:00'],
        ]);
        Place::create(['id' => 'p1', 'name' => 'Kütüphane', 'category' => 'Library', 'lat' => 1, 'lng' => 1]);
        $this->actingAsUser();

        config([
            'ai.provider' => 'local',
            'ai.fallback' => '',
            'ai.providers.local.base_url' => 'http://local.test/v1',
            'ai.providers.local.model' => 'arucad-ask',
            'ai.cache.enabled' => false,
        ]);
        Http::fake(['local.test/*' => Http::response([
            'choices' => [['message' => ['content' => 'Burs bilgisi sayfada.']]],
        ], 200)]);

        $sources = $this->postJson('/api/v1/ai/query', ['prompt' => 'başvuru oranları ve koşulları nedir'])
            ->assertOk()->json('data.sources');

        $this->assertNotEmpty($sources);
        foreach ($sources as $source) {
            $this->assertArrayHasKey('authority', $source);
            $this->assertArrayHasKey('authorityLabel', $source);
            $this->assertArrayHasKey('freshness', $source);
            $this->assertArrayHasKey('stale', $source);
            // No table names, no column names, no internal ids beyond our
            // own opaque tool key.
            $this->assertStringNotContainsString('knowledge_documents', json_encode($source));
        }

        // Strongest first, so the UI leads with what decided the answer.
        $authorities = array_column($sources, 'authority');
        $sorted = $authorities;
        rsort($sorted);
        $this->assertSame($sorted, $authorities);
    }

    // --------------------------------------------------- routing sanity

    public function test_the_agent_selects_sources_by_domain_not_by_sending_everything(): void
    {
        Place::create(['id' => 'p1', 'name' => 'Kütüphane', 'category' => 'Library', 'lat' => 1, 'lng' => 1]);
        ShuttleRoute::create([
            'id' => 'r1', 'name' => 'Lefkosa Servisi', 'color_key' => 'blue',
            'stops' => ['A'], 'departures' => ['08:00'],
        ]);
        $this->liveEvent('Konser', '19:00');

        $shuttle = app(AruverseAgent::class)->buildContext('servis saatleri ne zaman');
        $this->assertContains('shuttle', $shuttle['tools']);
        $this->assertNotContains('career', $shuttle['tools'],
            'A shuttle question must not drag in every table.');

        $events = app(AruverseAgent::class)->buildContext('bugün hangi etkinlikler var');
        $this->assertContains('events', $events['tools']);
    }

    public function test_the_same_fact_is_reached_in_all_three_languages(): void
    {
        ShuttleRoute::create([
            'id' => 'r1', 'name' => 'Lefkosa Servisi', 'color_key' => 'blue',
            'stops' => ['Kampus', 'Lefkosa'], 'departures' => ['07:00'],
        ]);

        foreach (['servis saatleri', 'shuttle times', 'расписание автобусов'] as $question) {
            $context = app(AruverseAgent::class)->buildContext($question);
            $this->assertStringContainsString('07:00', $context['context'],
                "The same underlying row must be reached for: {$question}");
        }
    }
}
