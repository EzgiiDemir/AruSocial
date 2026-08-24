<?php

namespace Tests\Feature;

use App\Models\FeedPost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeedPaginationTest extends TestCase
{
    use RefreshDatabase;

    private function seedPosts(int $count): void
    {
        $author = $this->actingAsUser();
        for ($i = 1; $i <= $count; $i++) {
            FeedPost::create([
                'id' => 'post-'.$i,
                'author_id' => $author->id,
                'name' => $author->name,
                'text' => 'post '.$i,
                'meta' => '',
                'created_at' => now()->subMinutes($count - $i),
            ]);
        }
    }

    public function test_feed_pages_are_stable_and_do_not_overlap(): void
    {
        $this->seedPosts(25);

        $page1 = $this->getJson('/api/v1/feed?page=1&perPage=10')->assertOk();
        $page2 = $this->getJson('/api/v1/feed?page=2&perPage=10')->assertOk();
        $page3 = $this->getJson('/api/v1/feed?page=3&perPage=10')->assertOk();

        $ids1 = collect($page1->json('data'))->pluck('id');
        $ids2 = collect($page2->json('data'))->pluck('id');
        $ids3 = collect($page3->json('data'))->pluck('id');

        $this->assertCount(10, $ids1);
        $this->assertCount(10, $ids2);
        $this->assertCount(5, $ids3);
        $this->assertEmpty($ids1->intersect($ids2));
        $this->assertEmpty($ids1->intersect($ids3));
        $this->assertEmpty($ids2->intersect($ids3));
        $this->assertSame(['post-25', 'post-24'], $ids1->take(2)->all());
        $this->assertSame(25, $page1->json('meta.pagination.total'));
        $this->assertSame(3, $page1->json('meta.pagination.lastPage'));
        $this->assertSame(1, $page1->json('meta.pagination.currentPage'));
        $this->assertSame(10, $page1->json('meta.pagination.perPage'));
    }

    public function test_default_feed_response_includes_pagination_meta(): void
    {
        $this->seedPosts(3);
        $this->getJson('/api/v1/feed')
            ->assertOk()
            ->assertJsonPath('meta.pagination.currentPage', 1)
            ->assertJsonPath('meta.pagination.perPage', 20)
            ->assertJsonPath('meta.pagination.total', 3)
            ->assertJsonPath('meta.pagination.lastPage', 1);
    }

    public function test_feed_rejects_an_oversized_page(): void
    {
        $this->actingAsUser();
        $this->getJson('/api/v1/feed?perPage=100000')
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'VALIDATION');
    }

    public function test_feed_rejects_a_non_positive_page(): void
    {
        $this->actingAsUser();
        $this->getJson('/api/v1/feed?page=0')
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'VALIDATION');
    }
}
