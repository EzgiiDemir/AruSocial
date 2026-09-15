<?php

namespace Tests\Feature;

use App\Models\AskConversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AskConversationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_successful_ai_turn_is_stored_on_the_account(): void
    {
        $user = $this->actingAsUser();
        config(['services.groq.key' => 'test-key']);
        Http::fake([
            'api.groq.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'Kütüphane Meditation binasında.']]],
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/ai/query', ['prompt' => 'Kütüphane nerede?']);
        $response->assertOk();
        $conversationId = $response->json('data.conversationId');
        $this->assertNotEmpty($conversationId);

        $this->assertDatabaseHas('ask_conversations', [
            'id' => $conversationId,
            'user_id' => $user->id,
        ]);
        $this->assertDatabaseCount('ask_messages', 2);

        $this->getJson('/api/v1/ask/conversations')
            ->assertOk()
            ->assertJsonPath('data.0.id', $conversationId);

        $show = $this->getJson("/api/v1/ask/conversations/{$conversationId}");
        $show->assertOk();
        $this->assertCount(2, $show->json('data.messages'));

        $this->postJson("/api/v1/ask/conversations/{$conversationId}/delete")->assertOk();
        $this->assertNull(AskConversation::find($conversationId));
    }

    public function test_another_account_cannot_read_ask_history(): void
    {
        $this->actingAsUser();
        config(['services.groq.key' => 'test-key']);
        Http::fake([
            'api.groq.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'Cevap.']]],
            ], 200),
        ]);
        $id = $this->postJson('/api/v1/ai/query', ['prompt' => 'Merhaba'])->json('data.conversationId');

        $other = User::create([
            'name' => 'Other',
            'email' => 'ask-other@arucad.edu.tr',
            'password' => bcrypt('x'),
        ]);
        $this->actingAsUser($other);
        $this->getJson("/api/v1/ask/conversations/{$id}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'ASK_CONVERSATION_NOT_FOUND');
    }
}
