<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Ai\AiPrivacy;
use App\Services\Ai\AiResponder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Privacy hotfix: a personalized answer must never be shared through the
 * cross-user AI answer cache — not written, not read, not served from an
 * entry written before the fix — while public answers still cache.
 */
class AiPersonalAnswerCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::preventStrayRequests();
        config(['knowledge.on_demand_enabled' => false, 'knowledge.embeddings.enabled' => false, 'ai.web_research.enabled' => false,
            'ai.provider' => 'local', 'ai.fallback' => '', 'ai.providers.local.base_url' => 'http://local.test/v1', 'ai.providers.local.model' => 'm',
            'ai.cache.enabled' => true, 'ai.retries' => 0, 'ai.direct_answers' => false]);
    }

    private function replies(string ...$texts): void
    {
        $sequence = Http::sequence();
        foreach ($texts as $text) {
            $sequence->push(['choices' => [['message' => ['content' => $text], 'finish_reason' => 'stop']]]);
        }
        Http::fake(['local.test/*' => $sequence]);
    }

    /** A fresh request lifecycle for the next user. */
    private function ask(User $user, array $payload): array
    {
        $this->app->forgetScopedInstances();
        $this->actingAs($user);

        return $this->postJson('/api/v1/ai/query', $payload)->assertOk()->json('data');
    }

    private function student(): User
    {
        $user = $this->actingAsRole('student');
        $this->app['auth']->forgetGuards();

        return $user;
    }

    public function test_identical_personal_questions_get_each_users_own_answer(): void
    {
        $this->replies('Profilinde bölümün Grafik, seviyen 3.', 'Profilinde bölümün Mimarlık, seviyen 1.');
        $a = $this->student();
        $b = $this->student();

        $first = $this->ask($a, ['prompt' => 'profilim ne durumda?']);
        $second = $this->ask($b, ['prompt' => 'profilim ne durumda?']);

        $this->assertStringContainsString('Grafik', $first['answer']);
        $this->assertStringContainsString('Mimarlık', $second['answer']);
        $this->assertNotSame('cached', $second['aiMode']);
        Http::assertSentCount(2);
    }

    public function test_an_entry_written_before_the_fix_is_never_read(): void
    {
        // An old-format shared entry for the same question (pre-fix key namespace).
        $responder = new \ReflectionMethod(AiResponder::class, 'cacheKey');
        $current = $responder->invoke(app(AiResponder::class), 'profilim ne durumda?');
        $this->assertStringStartsWith('ai:answer:v2:', $current);
        Cache::put(str_replace('ai:answer:v2:', 'ai:answer:', $current), 'Profilinde bölümün Grafik, seviyen 3.', 3600);
        // Even a planted entry under the CURRENT key is not read for a personal question.
        Cache::put($current, 'Profilinde bölümün Grafik, seviyen 3.', 3600);
        $this->replies('Profilinde bölümün Mimarlık, seviyen 1.');

        $answer = $this->ask($this->student(), ['prompt' => 'profilim ne durumda?']);

        $this->assertStringNotContainsString('Grafik', $answer['answer']);
        Http::assertSentCount(1);
    }

    public function test_a_mixed_public_and_personal_question_is_not_shared(): void
    {
        // Public part (scholarships) + personal part (my clubs) in one question.
        $this->replies('Burs başvurusu Ağustos ayında; kulüplerin: Fotoğraf.', 'Burs başvurusu Ağustos ayında; kulüplerin: Dans.');

        $this->ask($this->student(), ['prompt' => 'burs başvurusu ne zaman ve kulüplerim hangileri?']);
        $second = $this->ask($this->student(), ['prompt' => 'burs başvurusu ne zaman ve kulüplerim hangileri?']);

        $this->assertNotSame('cached', $second['aiMode']);
        $this->assertStringNotContainsString('Fotoğraf', $second['answer']);
        Http::assertSentCount(2);
    }

    public function test_a_follow_up_to_a_personal_question_is_never_cached(): void
    {
        $this->replies('Randevun Titan binasında.', 'Randevun Meditation binasında.');
        $history = [['role' => 'user', 'content' => 'bir sonraki randevum ne zaman?'], ['role' => 'assistant', 'content' => 'Yarın.']];

        // "Where is it?" carries no personal keyword; its meaning comes from the conversation.
        $this->ask($this->student(), ['prompt' => 'nerede?', 'messages' => [...$history, ['role' => 'user', 'content' => 'nerede?']]]);
        $second = $this->ask($this->student(), ['prompt' => 'nerede?', 'messages' => [...$history, ['role' => 'user', 'content' => 'nerede?']]]);

        $this->assertNotSame('cached', $second['aiMode']);
        Http::assertSentCount(2);
        $key = (new \ReflectionMethod(AiResponder::class, 'cacheKey'))->invoke(app(AiResponder::class), 'nerede?');
        $this->assertFalse(Cache::has($key), 'nothing was written to the shared answer cache for the follow-up');
    }

    public function test_a_public_question_still_caches_across_users(): void
    {
        $this->replies('Kütüphane hafta içi 09:00-17:00 açıktır.');

        $this->ask($this->student(), ['prompt' => 'kütüphane kaçta kapanır']);
        $second = $this->ask($this->student(), ['prompt' => 'kütüphane kaçta kapanır']);

        $this->assertSame('cached', $second['aiMode']);
        Http::assertSentCount(1);
    }

    public function test_a_staff_member_and_a_student_never_share_a_personal_answer(): void
    {
        $this->replies('Profilinde seviyen 9 (yönetici).', 'Profilinde seviyen 1.');
        $staff = $this->actingAsRole('platformAdmin');
        $this->app['auth']->forgetGuards();

        $this->ask($staff, ['prompt' => 'profilim ne durumda?']);
        $student = $this->ask($this->student(), ['prompt' => 'profilim ne durumda?']);

        $this->assertStringNotContainsString('yönetici', $student['answer']);
        Http::assertSentCount(2);
    }

    public function test_an_unclassified_call_is_treated_as_personal_and_never_cached(): void
    {
        $this->replies('cevap 1', 'cevap 2');
        $responder = app(AiResponder::class);

        $responder->generate(fn () => [['role' => 'user', 'content' => 'soru']], 'soru');
        $second = $responder->generate(fn () => [['role' => 'user', 'content' => 'soru']], 'soru');
        $this->assertFalse($second->cached);

        $public = $responder->generate(fn () => [['role' => 'user', 'content' => 'x']], 'x', null, AiPrivacy::public());
        $this->assertFalse($public->cached);
    }
}
