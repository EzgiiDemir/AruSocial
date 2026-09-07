<?php

namespace Tests\Feature;

use App\Support\SchemaColumnCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class LegacySchemaCompatTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        // RefreshDatabase restores the real schema for the next test, but
        // SchemaColumnCache is a static, per-process cache that a migration
        // rollback/rerun doesn't know to invalidate — clear it explicitly so
        // a later test file doesn't inherit this test's "column missing"
        // answer.
        SchemaColumnCache::forget('feed_posts', 'workflow_status');
        parent::tearDown();
    }

    public function test_ai_query_returns_answer_when_ask_tables_are_missing(): void
    {
        $this->actingAsUser();
        Schema::dropIfExists('ask_messages');
        Schema::dropIfExists('ask_conversations');
        config(['services.groq.key' => '']);

        $response = $this->postJson('/api/v1/ai/query', ['prompt' => 'Merhaba']);

        $response->assertOk();
        $response->assertJsonPath('error', null);
        $this->assertNotEmpty($response->json('data.answer'));
        $this->assertNull($response->json('data.conversationId'));
    }

    public function test_ask_conversations_list_is_empty_when_tables_are_missing(): void
    {
        $this->actingAsUser();
        Schema::dropIfExists('ask_messages');
        Schema::dropIfExists('ask_conversations');

        $this->getJson('/api/v1/ask/conversations')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.pagination.total', 0);
    }

    public function test_checkin_feed_post_omits_workflow_status_when_column_missing(): void
    {
        $this->actingAsUser();
        $place = \App\Models\Place::create([
            'id' => 'legacy-place',
            'name' => 'Legacy Place',
            'category' => 'Studio',
            'lat' => 35.337305,
            'lng' => 33.321303,
        ]);

        if (Schema::hasColumn('feed_posts', 'workflow_status')) {
            Schema::table('feed_posts', function ($table) {
                $table->dropIndex(['workflow_status']);
                $table->dropColumn(['workflow_status', 'review_note']);
            });
        }
        // SchemaColumnCache answers per worker process, not per request —
        // an earlier test in this run may have already cached "column
        // exists" before this test drops it.
        SchemaColumnCache::forget('feed_posts', 'workflow_status');

        $this->postJson('/api/v1/checkins', [
            'placeId' => $place->id,
            'latitude' => 35.337305,
            'longitude' => 33.321303,
            'visibleToOthers' => true,
        ])->assertOk();

        $this->assertDatabaseHas('feed_posts', [
            'text' => 'Legacy Place konumunda check-in yaptı',
        ]);
    }
}
