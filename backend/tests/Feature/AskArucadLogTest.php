<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Real Ask ARUCAD question logging (docs/EKSIKLER.md admin §5) — before
// this, AiController::query() was a stateless proxy that logged nothing,
// so "en çok sorulan sorular / kategori bazında istatistik" was
// structurally impossible. These tests prove a real row is written (with
// a real, keyword-derived category) regardless of whether Groq itself is
// configured.
class AskArucadLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_question_is_logged_with_a_real_category_even_without_groq_configured(): void
    {
        $this->actingAsUser();

        $response = $this->postJson('/api/v1/ai/query', ['prompt' => 'Yemekhane bugün ne var?']);

        // No GROQ_API_KEY in the test environment — the AI call itself
        // fails, but the question must still be logged first.
        $response->assertStatus(501);
        $this->assertDatabaseHas('ask_arucad_logs', [
            'question' => 'Yemekhane bugün ne var?',
            'category' => 'yemek',
        ]);
    }

    public function test_an_empty_prompt_is_not_logged(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/v1/ai/query', ['prompt' => '   ']);

        $this->assertDatabaseCount('ask_arucad_logs', 0);
    }

    public function test_admin_stats_reports_a_real_category_breakdown(): void
    {
        $this->actingAsUser(email: 'student1@arucad.edu.tr');
        $this->postJson('/api/v1/ai/query', ['prompt' => 'Kütüphane kaçta kapanıyor?']);
        $this->postJson('/api/v1/ai/query', ['prompt' => 'Yemekhane menüsü ne?']);

        $this->actingAsAdmin();
        $response = $this->getJson('/api/v1/admin/stats');

        $response->assertOk();
        $this->assertEquals(2, $response->json('data.askArucad.totalQuestions'));
        $categories = collect($response->json('data.askArucad.byCategory'))->pluck('total', 'category');
        $this->assertEquals(1, $categories['kampüs hizmetleri'] ?? null);
        $this->assertEquals(1, $categories['yemek'] ?? null);
    }
}
