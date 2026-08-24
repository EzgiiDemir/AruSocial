<?php

namespace Tests\Feature;

use App\Models\FeedPost;
use App\Models\Story;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FeedStoryAuthorFkTest extends TestCase
{
    use RefreshDatabase;

    public function test_author_id_is_an_integer_fk_and_unmatched_rows_are_kept_as_null(): void
    {
        $this->assertTrue(self::isIntegerColumnType(Schema::getColumnType('feed_posts', 'author_id')));
        $this->assertTrue(self::isIntegerColumnType(Schema::getColumnType('stories', 'author_id')));

        DB::table('feed_posts')->insert([
            'id' => 'post-orphan',
            'author_id' => null,
            'name' => 'Eski yazar',
            'text' => 'legacy post',
            'meta' => '',
            'created_at' => now(),
        ]);
        DB::table('stories')->insert([
            'id' => 'story-orphan',
            'author_id' => null,
            'author_name' => 'Eski yazar',
            'text' => 'legacy story',
            'created_at' => now(),
        ]);

        $this->assertDatabaseHas('feed_posts', ['id' => 'post-orphan', 'name' => 'Eski yazar', 'author_id' => null]);
        $this->assertDatabaseHas('stories', ['id' => 'story-orphan', 'author_name' => 'Eski yazar', 'author_id' => null]);
    }

    public function test_author_id_rejects_a_user_that_does_not_exist(): void
    {
        $this->expectException(QueryException::class);

        FeedPost::create([
            'id' => 'post-bad',
            'author_id' => 999999,
            'name' => 'Ghost',
            'text' => 'nope',
            'meta' => '',
            'created_at' => now(),
        ]);
    }

    public function test_deleting_a_user_cascades_their_posts_and_stories(): void
    {
        $user = $this->actingAsUser();
        $this->postJson('/api/v1/feed', ['text' => 'silinecek'])->assertOk();
        $this->postJson('/api/v1/stories', ['text' => 'silinecek hikaye'])->assertOk();

        $this->assertSame(1, FeedPost::count());
        $this->assertSame(1, Story::count());

        $user->delete();

        $this->assertSame(0, FeedPost::count());
        $this->assertSame(0, Story::count());
    }
}
