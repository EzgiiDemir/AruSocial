<?php

namespace Tests\Feature;

use App\Models\FeedPost;
use App\Models\FeedPostMedia;
use App\Models\MediaItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Posts that carry more than one picture.
 *
 * The compatibility rule these lock down: feed_posts.image_url keeps
 * meaning what it always meant. A post written by an old client, or read
 * by one, must be unaffected by any of this.
 */
class PostCarouselTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(MediaItem::disk());
        $this->me = $this->actingAsUser();
    }

    /** An approved, owned upload — the only kind a post may reference. */
    private function upload(string $name = 'shot.jpg'): string
    {
        $created = $this->post('/api/v1/media/mine', ['file' => $this->fakeJpeg($name)], [
            'Accept' => 'application/json',
        ])->assertCreated()->json('data');

        MediaItem::whereKey($created['id'])->update(['moderation_status' => 'approved']);

        return MediaItem::find($created['id'])->url();
    }

    public function test_a_single_image_post_still_uses_image_url_and_has_no_carousel_rows(): void
    {
        $url = $this->upload();

        $post = $this->postJson('/api/v1/feed', ['text' => 'one picture', 'imageUrl' => $url])
            ->assertOk()->json('data');

        $this->assertNotNull($post['imageUrl']);
        $this->assertSame([], $post['media'], 'One picture is not a carousel.');
        $this->assertSame(0, FeedPostMedia::count());
    }

    public function test_a_carousel_is_stored_in_order_and_still_sets_image_url(): void
    {
        $urls = [$this->upload('a.jpg'), $this->upload('b.jpg'), $this->upload('c.jpg')];

        $post = $this->postJson('/api/v1/feed', [
            'text' => 'three pictures',
            'media' => array_map(fn ($u) => ['imageUrl' => $u], $urls),
        ])->assertOk()->json('data');

        $this->assertCount(3, $post['media']);
        $this->assertSame([0, 1, 2], array_column($post['media'], 'sortOrder'));
        $this->assertSame(
            $post['imageUrl'], $post['media'][0]['imageUrl'],
            'The first item is what an old client shows.',
        );
    }

    public function test_the_author_order_is_kept_rather_than_the_upload_order(): void
    {
        $a = $this->upload('a.jpg');
        $b = $this->upload('b.jpg');

        $post = $this->postJson('/api/v1/feed', [
            'text' => 'reordered',
            'media' => [['imageUrl' => $b], ['imageUrl' => $a]],
        ])->assertOk()->json('data');

        $this->assertSame($b, $post['media'][0]['imageUrl']);
        $this->assertSame($b, $post['imageUrl']);
    }

    public function test_more_than_ten_images_is_refused(): void
    {
        $urls = [];
        for ($i = 0; $i < 11; $i++) {
            $urls[] = ['imageUrl' => $this->upload("p{$i}.jpg")];
        }

        $this->postJson('/api/v1/feed', ['text' => 'too many', 'media' => $urls])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'TOO_MANY_MEDIA');

        $this->assertSame(0, FeedPost::count(), 'Nothing may be published when the post is refused.');
    }

    /**
     * The whole point of resolving every item before writing any of them.
     */
    public function test_one_unusable_item_publishes_nothing_at_all(): void
    {
        $good = $this->upload('good.jpg');

        $this->postJson('/api/v1/feed', [
            'text' => 'partly broken',
            'media' => [
                ['imageUrl' => $good],
                ['imageUrl' => 'http://127.0.0.1:4000/api/v1/media/media-does-not-exist/file'],
            ],
        ])->assertStatus(422)->assertJsonPath('error.code', 'MEDIA_NOT_FOUND');

        $this->assertSame(0, FeedPost::count());
        $this->assertSame(0, FeedPostMedia::count());
    }

    public function test_another_accounts_upload_cannot_be_smuggled_in_behind_a_good_one(): void
    {
        $mine = $this->upload('mine.jpg');

        $other = User::create([
            'name' => 'Other', 'email' => 'other@arucad.edu.tr', 'password' => bcrypt('x'),
        ]);
        $theirs = MediaItem::create([
            'id' => 'media-theirs', 'user_id' => $other->id,
            'file_path' => 'media/theirs.jpg', 'file_name' => 'theirs.jpg',
            'mime_type' => 'image/jpeg', 'size_bytes' => 10, 'uploaded_at' => now(),
            'uploaded_by' => $other->name, 'moderation_status' => 'approved',
        ]);

        $this->postJson('/api/v1/feed', [
            'text' => 'nope',
            'media' => [['imageUrl' => $mine], ['imageUrl' => $theirs->url()]],
        ])->assertStatus(422)->assertJsonPath('error.code', 'MEDIA_NOT_OWNED');

        $this->assertSame(0, FeedPost::count());
    }

    public function test_the_same_image_twice_is_refused(): void
    {
        $url = $this->upload();

        $this->postJson('/api/v1/feed', [
            'text' => 'twice',
            'media' => [['imageUrl' => $url], ['imageUrl' => $url]],
        ])->assertStatus(422)->assertJsonPath('error.code', 'DUPLICATE_MEDIA');
    }

    public function test_a_legacy_post_with_only_image_url_reads_back_with_an_empty_carousel(): void
    {
        $post = FeedPost::create([
            'id' => 'post-legacy', 'author_id' => $this->me->id, 'name' => 'Me',
            'text' => 'written before carousels', 'meta' => '',
            'image_url' => 'http://127.0.0.1:4000/api/v1/media/media-old/file',
            'created_at' => now(),
        ]);

        $row = collect($this->getJson('/api/v1/feed')->assertOk()->json('data'))
            ->firstWhere('id', $post->id);

        $this->assertNotNull($row);
        $this->assertSame([], $row['media']);
        $this->assertNull($row['styleJson']);
        $this->assertNull($row['altText']);
    }

    public function test_deleting_a_post_takes_its_carousel_rows_with_it(): void
    {
        $post = $this->postJson('/api/v1/feed', [
            'text' => 'bye',
            'media' => [['imageUrl' => $this->upload('a.jpg')], ['imageUrl' => $this->upload('b.jpg')]],
        ])->assertOk()->json('data');

        $this->assertSame(2, FeedPostMedia::count());

        $this->postJson('/api/v1/feed/'.$post['id'].'/delete')->assertOk();

        $this->assertSame(0, FeedPostMedia::count(), 'Rows must cascade, not linger.');
    }
}
