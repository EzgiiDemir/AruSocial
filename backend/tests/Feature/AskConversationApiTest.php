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

    /** A stored turn, using the same fake the other tests do. */
    private function startConversation(): string
    {
        config(['services.groq.key' => 'test-key']);
        Http::fake([
            'api.groq.com/*' => Http::response([
                'choices' => [['message' => ['content' => 'Kütüphane Meditation binasında.']]],
            ], 200),
        ]);

        $id = $this->postJson('/api/v1/ai/query', ['prompt' => 'Merhaba'])->json('data.conversationId');
        $this->assertNotEmpty($id);

        return (string) $id;
    }

    /**
     * Deleting a conversation that is already gone is not a failure.
     *
     * Reported from the app as "ASK_CONVERSATION_NOT_FOUND · POST
     * /ask/conversations/.../delete": the student saw a network error for the
     * outcome they had asked for. It happens on a double tap, on a retry
     * after a slow first request, and whenever the list on screen is older
     * than the server.
     */
    public function test_deleting_a_conversation_twice_succeeds_both_times(): void
    {
        $this->actingAsUser();
        $id = $this->startConversation();

        $this->postJson("/api/v1/ask/conversations/{$id}/delete")
            ->assertOk()
            ->assertJsonPath('data.deleted', true);

        // The second attempt is the one that used to return 404.
        $this->postJson("/api/v1/ask/conversations/{$id}/delete")
            ->assertOk()
            ->assertJsonPath('data.deleted', true);

        $this->assertNull(AskConversation::find($id));
    }

    /** An id that never existed is also "already gone". */
    public function test_deleting_an_unknown_conversation_succeeds(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/v1/ask/conversations/ask-does-not-exist/delete')
            ->assertOk()
            ->assertJsonPath('data.deleted', true);
    }

    /**
     * The reply is identical for somebody else's conversation, so the
     * idempotency reveals nothing — and their row must survive.
     */
    public function test_another_users_conversation_is_reported_gone_but_not_deleted(): void
    {
        $this->actingAsUser();
        $id = $this->startConversation();

        $other = User::create([
            'name' => 'Other',
            'email' => 'ask-other-delete@arucad.edu.tr',
            'password' => bcrypt('x'),
        ]);
        $this->actingAsUser($other);

        $this->postJson("/api/v1/ask/conversations/{$id}/delete")
            ->assertOk()
            ->assertJsonPath('data.deleted', true);

        // Told it is gone, and still there: the delete is scoped to the owner.
        $this->assertNotNull(AskConversation::find($id));
    }
}
