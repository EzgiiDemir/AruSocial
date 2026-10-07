<?php

namespace Tests\Feature;

use App\Services\Ai\AiBudget;
use App\Services\Ai\AiPrivacy;
use App\Services\Ai\AiResponder;
use App\Services\Ai\AiResult;
use App\Services\Ai\AiTelemetry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * What happens when the whole campus asks at once.
 *
 * The per-user limiter bounds one student. These are the limits that
 * bound everyone together, and the rule they all share: exceeding one is
 * never an error. The assistant falls back to answering from indexed
 * ARUCAD sources and says so, because a flatter answer beats a spinner.
 */
class AiCapacityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
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
            'ai.fallback' => '',
            'ai.providers.groq.api_key' => 'sk-test',
            'ai.budget.enabled' => true,
            'ai.budget.max_concurrent' => 2,
            'ai.budget.daily_requests' => 0,
            'ai.cache.enabled' => false,
        ]);
    }

    private function groqAnswers(string $text = 'cevap'): void
    {
        Http::fake(['api.groq.com/*' => Http::response([
            'choices' => [['message' => ['content' => $text]]],
        ])]);
    }

    private function ask(string $question = 'soru'): AiResult
    {
        return app(AiResponder::class)->generate(
            fn () => [['role' => 'user', 'content' => $question]],
        );
    }

    // ------------------------------------------------------------ the cap

    public function test_a_normal_request_passes_the_budget(): void
    {
        $this->groqAnswers('merhaba');

        $result = $this->ask();

        $this->assertSame('merhaba', $result->text);
    }

    /**
     * A lecture ends and hundreds of phones ask at once. Past the
     * ceiling, the extra requests answer from sources instead of
     * queueing on a provider that serves a handful at a time.
     */
    public function test_requests_past_the_concurrency_ceiling_degrade_instead_of_queueing(): void
    {
        $this->groqAnswers();
        $budget = app(AiBudget::class);

        // Two calls already in flight, the configured maximum.
        $this->assertNull($budget->claim());
        $this->assertNull($budget->claim());

        $result = $this->ask();

        $this->assertTrue($result->knowledgeOnly, 'A busy system must degrade, not fail.');
        $this->assertNull($result->text);
        Http::assertNothingSent();
    }

    public function test_a_finished_request_gives_its_slot_back(): void
    {
        $this->groqAnswers('ikinci cevap');
        $budget = app(AiBudget::class);

        $this->assertNull($budget->claim());
        $this->assertNull($budget->claim());
        $budget->release();

        $this->assertSame('ikinci cevap', $this->ask()->text);
    }

    /**
     * The slot must come back even when the provider throws, or a bad
     * afternoon permanently shrinks the ceiling.
     */
    public function test_a_failing_provider_does_not_leak_its_slot(): void
    {
        Http::fake(fn () => throw new \RuntimeException('upstream exploded'));

        try {
            $this->ask();
        } catch (\Throwable) {
            // Whether it throws or degrades is not what this pins.
        }

        $this->assertSame(0, app(AiBudget::class)->snapshot()['in_flight']);
    }

    // ----------------------------------------------------------- the quota

    public function test_the_daily_cap_stops_calls_for_the_rest_of_the_day(): void
    {
        config(['ai.budget.daily_requests' => 2]);
        $this->groqAnswers('cevap');

        $this->assertSame('cevap', $this->ask()->text);
        $this->assertSame('cevap', $this->ask()->text);

        $third = $this->ask();
        $this->assertTrue($third->knowledgeOnly, 'The cap must hold once it is reached.');
        Http::assertSentCount(2);
    }

    public function test_the_cap_counts_only_real_calls(): void
    {
        config(['ai.budget.daily_requests' => 5]);
        $this->groqAnswers();

        $this->ask();
        $this->ask();

        $this->assertSame(2, app(AiBudget::class)->usedToday());
    }

    /**
     * The cache is what makes a shared quota survive a campus: the same
     * question asked by four hundred students must cost one call. A
     * cached answer that still spent budget would close the door for no
     * reason.
     */
    public function test_a_cached_answer_costs_no_budget(): void
    {
        config(['ai.cache.enabled' => true, 'ai.budget.daily_requests' => 1]);
        $this->groqAnswers('yemekhane 08:00 - 19:00');

        // A public question: an unclassified call is treated as personal and never cached.
        $first = app(AiResponder::class)->generate(
            fn () => [['role' => 'user', 'content' => 'yemekhane saatleri']],
            'yemekhane saatleri',
            null,
            AiPrivacy::public(),
        );
        $second = app(AiResponder::class)->generate(
            fn () => [['role' => 'user', 'content' => 'yemekhane saatleri']],
            'yemekhane saatleri',
            null,
            AiPrivacy::public(),
        );

        $this->assertSame($first->text, $second->text);
        $this->assertTrue($second->cached);
        $this->assertSame(1, app(AiBudget::class)->usedToday(),
            'The second answer came from cache and must not have been charged.');
        Http::assertSentCount(1);
    }

    // ------------------------------------------------------- observability

    public function test_a_refusal_is_counted_so_it_can_be_seen(): void
    {
        config(['ai.budget.daily_requests' => 1]);
        $this->groqAnswers();

        $this->ask();
        $this->ask();

        $this->assertGreaterThan(0, app(AiTelemetry::class)->snapshot()[AiTelemetry::BUDGET_REFUSALS] ?? 0);
    }

    public function test_the_budget_can_be_switched_off_entirely(): void
    {
        config(['ai.budget.enabled' => false, 'ai.budget.max_concurrent' => 1]);
        $this->groqAnswers('sınırsız');
        $budget = app(AiBudget::class);

        $budget->claim();
        $budget->claim();
        $budget->claim();

        $this->assertSame('sınırsız', $this->ask()->text);
    }

    // ------------------------------------------------- provider bad days

    public function test_a_provider_outage_answers_from_sources_rather_than_failing(): void
    {
        Http::fake(['api.groq.com/*' => Http::response([], 503)]);

        $result = $this->ask();

        $this->assertTrue($result->knowledgeOnly);
        $this->assertNull($result->text);
    }

    public function test_an_exhausted_quota_answers_from_sources_rather_than_failing(): void
    {
        Http::fake(['api.groq.com/*' => Http::response(
            ['error' => ['message' => 'quota exceeded', 'type' => 'insufficient_quota']], 429,
        )]);

        $result = $this->ask();

        $this->assertTrue($result->knowledgeOnly);
    }

    /** With no key at all the assistant still answers; it just never calls out. */
    public function test_an_unconfigured_provider_still_produces_an_answer_path(): void
    {
        config(['ai.providers.groq.api_key' => '']);
        Http::fake();

        $result = $this->ask();

        $this->assertTrue($result->knowledgeOnly);
        Http::assertNothingSent();
    }
}
