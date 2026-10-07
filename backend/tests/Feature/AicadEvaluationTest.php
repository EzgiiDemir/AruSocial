<?php

namespace Tests\Feature;

use App\Filament\Pages\AskEvaluationCompare;
use App\Filament\Pages\AskPlayground;
use App\Filament\Resources\AiEvaluationCases\AiEvaluationCaseResource;
use App\Filament\Resources\AiEvaluationCases\Pages\ListAiEvaluationCases;
use App\Filament\Resources\AiEvaluationRuns\AiEvaluationRunResource;
use App\Filament\Resources\AiEvaluationRuns\Pages\ViewAiEvaluationRun;
use App\Filament\Resources\KnowledgeDocuments\Pages\ListKnowledgeDocuments;
use App\Models\AiEvaluationCase;
use App\Models\AiEvaluationResult;
use App\Models\AiEvaluationRun;
use App\Models\KnowledgeDocument;
use App\Models\Place;
use App\Models\RoleGrant;
use App\Models\ServiceItem;
use App\Services\Ai\AskDiagnostics;
use App\Services\Ai\Evaluation\AssertionEvaluator;
use App\Services\Ai\Evaluation\AssertionSuggester;
use App\Services\Ai\Evaluation\EvaluationComparator;
use App\Services\Ai\Evaluation\EvaluationRunner;
use App\Services\CampusAskFallback;
use App\Support\TextFold;
use Database\Seeders\AiEvaluationCaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The persisted AICAD evaluation system: assertion checking and stage
 * attribution, the runner (retrieval and full), metrics, comparison,
 * "save as test" proposals, the CLI, the seed set and permissions.
 */
class AicadEvaluationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        config([
            'ai.provider' => 'local',
            'ai.fallback' => '',
            'ai.providers.local.base_url' => 'http://local.test/v1',
            'ai.providers.local.model' => 'arucad-ask',
            'ai.retries' => 0,
            'ai.web_research.enabled' => false,
            'knowledge.on_demand_enabled' => false,
            'knowledge.embeddings.enabled' => false,
        ]);
        Place::create(['id' => 'titan', 'name' => 'Titan', 'category' => 'Admin', 'lat' => 35.338, 'lng' => 33.322, 'description' => 'Administration', 'distance' => '', 'density' => '', 'street' => '']);
        ServiceItem::create(['id' => 'student-affairs', 'title' => 'Öğrenci İşleri (Student Affairs)', 'category' => 'Administrative', 'description' => 'Kayıt', 'contact' => 'r@example.edu', 'building' => 'Titan']);
        $body = 'Burs oranları ve başvuru koşulları.';
        KnowledgeDocument::create([
            'id' => sha1('https://arucad.edu.tr/burslar/'), 'url' => 'https://arucad.edu.tr/burslar/', 'domain' => 'arucad.edu.tr',
            'title' => 'Burslar ve Ücretler', 'language' => 'tr', 'content' => $body, 'content_clean' => $body,
            'content_folded' => TextFold::fold($body), 'content_hash' => sha1($body), 'content_length' => 40,
            'fetched_at' => now(), 'is_stale' => false, 'document_status' => 'indexed',
        ]);
    }

    private function case(array $attributes): AiEvaluationCase
    {
        return AiEvaluationCase::create($attributes + ['name' => Str::random(8), 'mode' => 'retrieval', 'active' => true]);
    }

    private function evaluate(string $mode = 'retrieval', array $scope = [], ?string $as = null): AiEvaluationRun
    {
        $runner = app(EvaluationRunner::class);

        return $runner->execute($runner->createRun($mode, $scope, $as));
    }

    // --- Assertions and stage attribution --------------------------------

    public function test_a_failure_is_attributed_to_the_earliest_failing_stage(): void
    {
        $facts = app(AssertionEvaluator::class)->observe(
            app(AskDiagnostics::class)->run('burs oranları'),
        );

        $outcome = app(AssertionEvaluator::class)->evaluate([
            ['type' => 'response.served_by', 'values' => ['operational']],      // structured_response
            ['type' => 'routing.domains_include', 'values' => ['sports']],      // query_planning
            ['type' => 'retrieval.source_in_top', 'match' => 'burslar', 'n' => 3],  // passes
        ], $facts);

        $this->assertSame('query_planning', $outcome['failure_stage']);
        $this->assertCount(2, $outcome['failed']);
        $this->assertTrue($outcome['categories']['retrieval']);
        $this->assertFalse($outcome['categories']['routing']);
    }

    public function test_a_missing_source_is_retrieval_and_a_low_one_is_ranking(): void
    {
        $evaluator = app(AssertionEvaluator::class);
        $facts = ['knowledge' => [
            ['rank' => 1, 'title' => 'A', 'url' => 'https://x/a/'], ['rank' => 2, 'title' => 'B', 'url' => 'https://x/b/'],
            ['rank' => 3, 'title' => 'C', 'url' => 'https://x/c/'], ['rank' => 4, 'title' => 'Burslar', 'url' => 'https://x/burslar/'],
        ], 'citations' => [['title' => 'Burslar', 'url' => 'https://x/burslar/', 'fate' => 'dropped']],
            'routing' => null, 'entities' => null, 'served_by' => 'model', 'grounding' => null];

        $missing = $evaluator->evaluate([['type' => 'retrieval.source_in_top', 'match' => 'kutuphane', 'n' => 3]], $facts);
        $low = $evaluator->evaluate([['type' => 'retrieval.source_in_top', 'match' => 'burslar', 'n' => 3]], $facts);
        $dropped = $evaluator->evaluate([['type' => 'prompt.source_included', 'match' => 'burslar']], $facts);

        $this->assertSame('knowledge_retrieval', $missing['failure_stage']);
        $this->assertSame('source_ranking', $low['failure_stage']);
        $this->assertSame('rank 4', $low['failed'][0]['actual']);
        $this->assertSame('prompt_budget', $dropped['failure_stage'], 'found and ranked, then cut by the budget');
    }

    public function test_a_withheld_answer_is_a_grounding_failure_not_a_wording_one(): void
    {
        $facts = ['answer' => 'Bu soruya teyit edebildiğim bir cevap bulamadım.', 'served_by' => 'model',
            'grounding' => ['passed_first_draft' => false, 'regenerated' => true, 'regeneration_accepted' => false]];

        $outcome = app(AssertionEvaluator::class)->evaluate([['type' => 'response.contains', 'value' => 'İngilizce|English']], $facts);

        $this->assertSame('grounding', $outcome['failure_stage']);
    }

    // --- Runner ----------------------------------------------------------

    public function test_a_retrieval_run_checks_every_stage_without_calling_a_model(): void
    {
        Http::fake();
        $passing = $this->case(['question' => 'öğrenci işleri nerede', 'assertions' => [
            ['type' => 'routing.domains_include', 'values' => ['services']],
            ['type' => 'entity.resolves', 'entity_type' => 'service', 'entity_id' => 'student-affairs'],
            ['type' => 'operational.place_ids_include', 'values' => ['titan']],
        ]]);
        $failing = $this->case(['question' => 'burs oranları', 'assertions' => [
            ['type' => 'retrieval.source_in_top', 'match' => 'kutuphane', 'n' => 3],
        ]]);

        $run = $this->evaluate();

        Http::assertNothingSent();
        $this->assertSame(AiEvaluationRun::STATUS_FINISHED, $run->status);
        $this->assertSame([1, 1, 0], [$run->passed, $run->failed, $run->skipped]);
        $result = $run->results()->where('case_id', $failing->id)->first();
        $this->assertSame('knowledge_retrieval', $result->failure_stage);
        $this->assertNotEmpty($result->trace_id);
        $this->assertArrayHasKey('routing', $result->snapshot, 'the diagnostic snapshot is stored');
        $this->assertEquals(['hits' => 0, 'total' => 1, 'rate' => 0], $run->metrics['retrieval']['hit@10']);
        $this->assertSame(1, $run->metrics['failure_stages']['knowledge_retrieval']);
        $this->assertNotNull($run->retrieval_fingerprint);
        $this->assertSame(AiEvaluationResult::STATUS_PASSED, $run->results()->where('case_id', $passing->id)->value('status'));
    }

    public function test_previous_turns_go_through_the_real_follow_up_resolution(): void
    {
        $this->case(['question' => 'oraya nasıl giderim?', 'previous_turns' => [['role' => 'user', 'content' => 'Titan nerede?']],
            'assertions' => [['type' => 'follow_up.rewritten', 'expect' => true], ['type' => 'operational.place_ids_include', 'values' => ['titan']]]]);

        $this->assertSame(1, $this->evaluate()->passed);
    }

    public function test_a_full_answer_case_without_a_user_is_skipped_with_the_reason(): void
    {
        $this->case(['question' => 'burs oranları', 'mode' => 'full', 'assertions' => [['type' => 'grounding.passed', 'expect' => true]]]);

        $run = $this->evaluate('full');

        $this->assertSame(1, $run->skipped);
        $this->assertStringContainsString('needs a user', $run->results()->first()->snapshot['skip_reason']);
    }

    public function test_a_full_answer_case_runs_the_real_path_and_records_model_and_grounding(): void
    {
        $user = $this->actingAsRole('superAdmin');
        Http::fake(['local.test/*' => Http::response(['choices' => [['message' => ['content' => 'Burs oranları sayfada açıklanır.'], 'finish_reason' => 'stop']]])]);
        $this->case(['question' => 'burs oranları', 'mode' => 'full', 'assertions' => [
            ['type' => 'grounding.passed', 'expect' => true],
            ['type' => 'response.contains', 'value' => 'burs'],
            ['type' => 'response.ai_mode', 'values' => ['local']],
        ]]);

        $run = $this->evaluate('full', [], $user->email);

        $this->assertSame(1, $run->passed, json_encode($run->results()->first()->failed_assertions));
        $snapshot = $run->results()->first()->snapshot;
        $this->assertSame('local', $snapshot['model']['provider']);
        $this->assertArrayHasKey('model_ms', $snapshot['latency']);
    }

    public function test_an_unreachable_model_skips_the_case_instead_of_failing_or_passing_it(): void
    {
        $user = $this->actingAsRole('superAdmin');
        config(['ai.allow_knowledge_only_fallback' => true]);
        Http::fake(['local.test/*' => Http::response('down', 503)]);
        $this->case(['question' => 'burs oranları', 'mode' => 'full', 'assertions' => [['type' => 'grounding.passed', 'expect' => true]]]);

        $run = $this->evaluate('full', [], $user->email);

        $this->assertSame(1, $run->skipped);
        $this->assertStringContainsString('model unavailable', $run->results()->first()->snapshot['skip_reason']);
    }

    // --- Comparison ------------------------------------------------------

    public function test_two_runs_compare_case_by_case(): void
    {
        $case = $this->case(['question' => 'burs oranları', 'assertions' => [['type' => 'retrieval.source_in_top', 'match' => 'burslar', 'n' => 3]]]);
        $baseline = $this->evaluate();

        $case->update(['assertions' => [['type' => 'retrieval.source_in_top', 'match' => 'kutuphane', 'n' => 3]]]);
        $current = $this->evaluate();

        $diff = app(EvaluationComparator::class)->compare($baseline, $current);
        $this->assertCount(1, $diff['newly_failing']);
        $this->assertSame('knowledge_retrieval', $diff['newly_failing'][0]['stage_after']);
        $this->assertSame([], $diff['newly_passing']);
        $hit1 = collect($diff['metrics'])->firstWhere('metric', 'hit@1');
        $this->assertEquals([1, 0], [$hit1['before'], $hit1['after']]);
        $this->assertSame('rate', $hit1['kind']);
    }

    // --- Save as test ----------------------------------------------------

    public function test_save_as_test_proposes_loose_editable_assertions(): void
    {
        $retrieval = app(AssertionSuggester::class)->suggest(app(AskDiagnostics::class)->run('burs oranları'));
        $operational = app(AssertionSuggester::class)->suggest(app(AskDiagnostics::class)->run('öğrenci işleri nerede'));

        $this->assertContains(['type' => 'retrieval.source_in_top', 'match' => '/burslar/', 'n' => 3], $retrieval,
            'the page that ranked first becomes "in the top 3", not a score');
        foreach ([...$retrieval, ...$operational] as $assertion) {
            $this->assertArrayHasKey($assertion['type'], AssertionEvaluator::TYPES);
            $this->assertArrayNotHasKey('score', $assertion);
        }
        $this->assertContains(['type' => 'operational.place_ids_include', 'values' => ['titan']], $operational);
    }

    // --- CLI ---------------------------------------------------------------

    public function test_the_cli_exit_code_fails_on_required_cases_but_not_model_sensitive_ones(): void
    {
        $this->case(['question' => 'burs oranları', 'tags' => ['model_sensitive'], 'assertions' => [['type' => 'retrieval.source_in_top', 'match' => 'kutuphane', 'n' => 3]]]);

        $this->artisan('ask:evaluate', ['--retrieval-only' => true])->assertExitCode(0);
        $this->artisan('ask:evaluate', ['--retrieval-only' => true, '--strict' => true])->assertExitCode(1);

        $this->case(['question' => 'burs oranları', 'assertions' => [['type' => 'routing.domains_include', 'values' => ['sports']]]]);
        $this->artisan('ask:evaluate', ['--retrieval-only' => true])
            ->expectsOutputToContain('query_planning')
            ->assertExitCode(1);
    }

    public function test_the_cli_compares_runs(): void
    {
        $this->case(['question' => 'burs oranları', 'assertions' => [['type' => 'retrieval.source_in_top', 'match' => 'burslar', 'n' => 3]]]);
        $a = $this->evaluate();
        $b = $this->evaluate();

        $this->artisan('ask:evaluate', ['--compare' => "{$a->id},{$b->id}"])
            ->expectsOutputToContain('unchanged passing: 1')
            ->assertExitCode(0);
    }

    // --- Seed set ----------------------------------------------------------

    public function test_the_seed_set_is_valid_idempotent_and_never_overwrites_an_edit(): void
    {
        $this->seed(AiEvaluationCaseSeeder::class);
        $count = AiEvaluationCase::count();
        $this->assertGreaterThan(80, $count);
        foreach (AiEvaluationCase::all() as $case) {
            foreach ($case->assertions as $assertion) {
                $this->assertArrayHasKey($assertion['type'], AssertionEvaluator::TYPES, $case->name);
            }
        }
        $this->assertGreaterThan(0, AiEvaluationCase::where('mode', 'full')->count());

        $edited = AiEvaluationCase::first();
        $edited->update(['notes' => 'edited by an admin']);
        $this->seed(AiEvaluationCaseSeeder::class);

        $this->assertSame($count, AiEvaluationCase::count());
        $this->assertSame('edited by an admin', $edited->fresh()->notes);
    }

    // --- Fallback wording --------------------------------------------------

    public function test_a_withheld_answer_is_not_described_as_an_outage(): void
    {
        $fallback = app(CampusAskFallback::class);

        foreach (['xyzzy plugh nedir?' => 'tr', 'what is xyzzy plugh?' => 'en', 'что такое xyzzy plugh?' => 'ru'] as $q => $lang) {
            $unverified = $fallback->answer($q, CampusAskFallback::REASON_UNVERIFIED);
            $unavailable = $fallback->answer($q, CampusAskFallback::REASON_UNAVAILABLE);
            $this->assertNotSame($unverified, $unavailable, $lang);
            foreach (['ulaşamadığım', 'cannot reach', 'недоступен'] as $outage) {
                $this->assertStringNotContainsString($outage, $unverified, $lang);
            }
        }
    }

    public function test_the_api_says_unverified_when_grounding_rejects_both_drafts(): void
    {
        $this->actingAsRole('student');
        Http::fake(['local.test/*' => Http::response(['choices' => [['message' => ['content' => 'Detaylar https://invented.example/burs adresinde.'], 'finish_reason' => 'stop']]])]);

        $response = $this->postJson('/api/v1/ai/query', ['prompt' => 'xyzzy plugh burs nedir?'])->assertOk();
        $answer = (string) $response->json('data.answer');

        $this->assertNotSame('', $answer, 'a real answer came back, not an error');
        $this->assertSame('knowledge_only', $response->json('data.aiMode'));
        // The withheld draft is replaced by a sourced excerpt of the page,
        // and nothing claims the AI is unavailable.
        $this->assertStringContainsString('Kaynak: https://arucad.edu.tr/burslar/', $answer);
        $this->assertStringNotContainsString('ulaşamadığım', $answer);
        $this->assertStringNotContainsString('kullanılamadığı', $answer);
        $this->assertStringNotContainsString('invented.example', $answer);
    }

    // --- Screens -----------------------------------------------------------

    public function test_the_screens_render_and_save_as_test_creates_an_edited_case(): void
    {
        Filament::setCurrentPanel('admin');
        $admin = $this->actingAsRole('platformAdmin');
        RoleGrant::create(['id' => (string) Str::uuid(), 'user_id' => $admin->id, 'role' => 'platformAdmin', 'status' => 'active', 'assigned_by' => 'test']);
        $this->case(['name' => 'failing case', 'question' => 'burs oranları', 'assertions' => [['type' => 'retrieval.source_in_top', 'match' => 'kutuphane', 'n' => 3]]]);
        $first = $this->evaluate();
        $second = $this->evaluate();

        Livewire::test(ListAiEvaluationCases::class)
            ->assertOk()->assertSee('failing case')
            ->filterTable('last_stage', 'knowledge_retrieval')->assertSee('failing case')
            ->filterTable('last_stage', 'grounding')->assertDontSee('failing case');
        // Filament injects filter closures by parameter name; a misnamed one
        // only breaks once the filter is used.
        Livewire::test(ListKnowledgeDocuments::class)
            ->filterTable('thin')->assertOk()->assertSee('Burslar')
            ->filterTable('unembedded')->assertOk()
            ->filterTable('errors')->assertOk();
        Livewire::test(ViewAiEvaluationRun::class, ['record' => $first->id])
            ->assertOk()->assertSee('knowledge retrieval')->assertSee('failing case');
        Livewire::withQueryParams(['baseline' => $first->id, 'current' => $second->id])
            ->test(AskEvaluationCompare::class)
            ->assertOk()->assertSee(__('panel.aicad_runs.unchanged_failing'));

        Livewire::withQueryParams(['question' => 'burs oranları'])
            ->test(AskPlayground::class)
            ->assertSet('question', 'burs oranları')
            ->call('run')
            ->callAction('saveAsTest', data: [
                'name' => 'scholarships from playground',
                'locale' => 'tr',
                'mode' => 'retrieval',
                'tags' => ['playground'],
                'assertions' => [['type' => 'retrieval.source_in_top', 'match' => '/burslar/', 'n' => 3]],
            ])
            ->assertHasNoActionErrors();

        $saved = AiEvaluationCase::where('name', 'scholarships from playground')->firstOrFail();
        $this->assertSame('burs oranları', $saved->question);
        $this->assertSame($admin->email, $saved->created_by);
        $this->assertSame([['type' => 'retrieval.source_in_top', 'match' => '/burslar/', 'n' => 3]], $saved->assertions);
    }

    // --- Permissions -------------------------------------------------------

    public function test_only_ai_prompt_holders_reach_the_test_screens(): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAsRole('student');
        $this->assertFalse(AiEvaluationCaseResource::canViewAny());
        $this->assertFalse(AiEvaluationRunResource::canViewAny());
        $this->assertFalse(AskEvaluationCompare::canAccess());

        $admin = $this->actingAsRole('platformAdmin');
        RoleGrant::create(['id' => (string) Str::uuid(), 'user_id' => $admin->id, 'role' => 'platformAdmin', 'status' => 'active', 'assigned_by' => 'test']);
        $this->assertTrue(AiEvaluationCaseResource::canCreate());
        $this->assertTrue(AskEvaluationCompare::canAccess());
        $this->assertFalse(AiEvaluationRunResource::canCreate(), 'runs are written by the runner only');
    }
}
