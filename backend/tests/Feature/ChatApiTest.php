<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Real bug fix (docs/EKSIKLER.md sosyal/chat): messages used to be scoped
// only to the sender's own copy — a real second account never actually
// received anything. These tests prove two distinct real accounts now
// share the same conversation.
class ChatApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_message_is_visible_in_the_senders_own_thread(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/chat/Someone/messages', ['text' => 'merhaba'])->assertOk();

        $messages = $this->getJson('/api/v1/chat/Someone/messages')->json('data');
        $this->assertCount(1, $messages);
        $this->assertEquals('merhaba', $messages[0]['text']);
        $this->assertTrue($messages[0]['fromMe']);
    }

    public function test_a_message_to_a_real_second_account_actually_reaches_them(): void
    {
        $this->actingAsAdmin(name: 'Sender Person', email: 'sender@arucad.edu.tr');
        $recipient = User::create(['name' => 'Recipient Person', 'email' => 'recipient@arucad.edu.tr', 'password' => bcrypt('x')]);

        $this->postJson('/api/v1/chat/Recipient Person/messages', ['text' => 'gerçek mesaj'])->assertOk();

        // The recipient's own session now sees it as a real incoming
        // message, not just the sender's outgoing copy.
        Sanctum::actingAs($recipient);
        $theirMessages = $this->getJson('/api/v1/chat/Sender Person/messages')->json('data');
        $this->assertCount(1, $theirMessages);
        $this->assertEquals('gerçek mesaj', $theirMessages[0]['text']);
        $this->assertFalse($theirMessages[0]['fromMe']);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $recipient->id, 'kind' => 'message',
        ]);
    }

    public function test_a_message_to_a_name_with_no_real_account_only_stays_in_the_senders_thread(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/chat/Nobody Real/messages', ['text' => 'hello'])->assertOk();

        // No exception, no orphan row for a non-existent user — just the
        // sender's own copy, same as before.
        $this->assertDatabaseCount('chat_messages', 1);
    }

    public function test_threads_reports_a_real_last_message_and_unread_count_that_clears_on_open(): void
    {
        $this->actingAsAdmin(name: 'Sender Person', email: 'sender@arucad.edu.tr');
        $recipient = User::create(['name' => 'Recipient Person', 'email' => 'recipient@arucad.edu.tr', 'password' => bcrypt('x')]);

        $this->postJson('/api/v1/chat/Recipient Person/messages', ['text' => 'ilk mesaj'])->assertOk();
        $this->postJson('/api/v1/chat/Recipient Person/messages', ['text' => 'ikinci mesaj'])->assertOk();

        Sanctum::actingAs($recipient);
        $threads = $this->getJson('/api/v1/chat/threads')->json('data');
        $this->assertCount(1, $threads);
        $this->assertEquals('ikinci mesaj', $threads[0]['lastMessage']);
        $this->assertEquals(2, $threads[0]['unreadCount']);

        // Opening the thread marks it read.
        $this->getJson('/api/v1/chat/Sender Person/messages')->assertOk();
        $afterOpen = $this->getJson('/api/v1/chat/threads')->json('data');
        $this->assertEquals(0, $afterOpen[0]['unreadCount']);
    }

    public function test_sending_an_empty_message_is_rejected(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/v1/chat/Someone/messages', ['text' => '   '])
            ->assertStatus(400);
    }
}
