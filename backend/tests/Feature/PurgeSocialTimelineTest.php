<?php

namespace Tests\Feature;

use App\Models\FeedPost;
use App\Models\MediaItem;
use App\Models\ModerationEvent;
use App\Models\PostComment;
use App\Models\PostLike;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Clearing the Social timeline.
 *
 * Two things have to be true at once and they pull against each other: the
 * posts and everything that describes them have to be gone, with no
 * orphaned rows and no files left in storage — and the moderation record of
 * what was decided about people has to survive untouched, because that is
 * what answers "why was my content removed" to a student, an appeal, or an
 * app store reviewer.
 */
class PurgeSocialTimelineTest extends TestCase
{
    use RefreshDatabase;

    private User $author;

    /**
     * The backup deliberately lands on the real filesystem rather than on a
     * disk, because a purge that only backs up to something `Storage::fake`
     * can swallow is not a backup. That makes it this test's job to point the
     * command somewhere disposable: without this, every run left a real
     * `storage/app/backups/social-timeline-*` folder behind in the working
     * app, and forty-one of them had accumulated before anyone noticed.
     */
    private string $backupDir;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(MediaItem::disk());
        $this->backupDir = sys_get_temp_dir().'/purge-timeline-test-'.Str::random(8);

        $this->author = User::create([
            'name' => 'Author',
            'email' => 'author@arucad.edu.tr',
            'password' => bcrypt('x'),
        ]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->backupDir)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->backupDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->backupDir);
        }

        parent::tearDown();
    }

    /**
     * Every invocation goes through here so none can forget `--backup-dir`.
     */
    private function purge(array $options = []): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan(
            'social:purge-timeline',
            $options + ['--backup-dir' => $this->backupDir],
        );
    }

    private function makePost(string $id, string $status = 'approved'): FeedPost
    {
        return FeedPost::create([
            'id' => $id,
            'author_id' => $this->author->id,
            'name' => 'Author',
            'text' => 'a post',
            'meta' => '',
            'created_at' => now(),
            'moderation_status' => $status,
        ]);
    }

    private function mediaBackedPost(string $id): array
    {
        $mediaId = 'media-'.Str::uuid();
        $path = 'media/'.Str::random(12).'.png';

        Storage::disk(MediaItem::disk())->put($path, 'bytes');

        $media = MediaItem::create([
            'id' => $mediaId,
            'file_path' => $path,
            'file_name' => basename($path),
            'mime_type' => 'image/png',
            'size_bytes' => 5,
            'uploaded_at' => now(),
            'uploaded_by' => $this->author->email,
            'user_id' => $this->author->id,
        ]);

        $post = $this->makePost($id);
        $post->image_url = "http://localhost/api/v1/media/{$mediaId}/file";
        $post->save();

        return [$post, $media, $path];
    }

    // ---- the dry run is genuinely dry ------------------------------------

    public function test_without_force_nothing_is_deleted(): void
    {
        $this->makePost('post-1');

        $this->purge()
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertSame(1, FeedPost::withoutGlobalScopes()->count());
    }

    // ---- what goes -------------------------------------------------------

    public function test_the_timeline_is_emptied(): void
    {
        $this->makePost('post-1');
        $this->makePost('post-2');

        $this->purge(['--force' => true])->assertSuccessful();

        $this->assertSame(0, FeedPost::withoutGlobalScopes()->count());
    }

    /**
     * A post held for review is hidden by the model's `approved-content`
     * global scope. Missing it would empty the screen while the row stayed.
     */
    public function test_posts_held_for_review_are_purged_too(): void
    {
        $this->makePost('post-pending', 'pending');
        $this->makePost('post-rejected', 'rejected');

        $this->purge(['--force' => true])->assertSuccessful();

        $this->assertSame(0, FeedPost::withoutGlobalScopes()->count());
    }

    public function test_comments_and_likes_go_with_their_post(): void
    {
        $post = $this->makePost('post-1');

        PostComment::create([
            'id' => 'comment-1',
            'post_id' => $post->id,
            'user_id' => $this->author->id,
            'text' => 'nice',
            'created_at' => now(),
        ]);
        PostLike::create([
            'id' => 'like-1',
            'post_id' => $post->id,
            'user_id' => $this->author->id,
            'created_at' => now(),
        ]);

        $this->purge(['--force' => true])->assertSuccessful();

        $this->assertSame(0, DB::table('post_comments')->count());
        $this->assertSame(0, DB::table('post_likes')->count());
    }

    public function test_no_orphaned_rows_are_left_behind(): void
    {
        $post = $this->makePost('post-1');
        PostComment::create([
            'id' => 'comment-1',
            'post_id' => $post->id,
            'user_id' => $this->author->id,
            'text' => 'nice',
            'created_at' => now(),
        ]);

        $this->purge(['--force' => true])
            ->expectsOutputToContain('No orphaned rows left behind')
            ->assertSuccessful();
    }

    /**
     * `image_url` is the API route that serves the file, keyed by the media
     * id — not a filename. Matching on a filename finds the literal segment
     * "file" and therefore no media at all, which is how a purge reports
     * success while leaving every picture on disk.
     */
    public function test_attached_media_rows_and_files_are_removed(): void
    {
        [, $media, $path] = $this->mediaBackedPost('post-1');

        $this->assertTrue(Storage::disk(MediaItem::disk())->exists($path));

        $this->purge(['--force' => true])->assertSuccessful();

        $this->assertNull(MediaItem::find($media->id));
        $this->assertFalse(Storage::disk(MediaItem::disk())->exists($path));
    }

    /**
     * A file also used somewhere that is not being cleared has to stay, or
     * the purge blanks images out of screens nobody asked to clear.
     */
    public function test_media_used_elsewhere_is_kept(): void
    {
        [, $media, $path] = $this->mediaBackedPost('post-1');
        $media->used_in = ['place-cover-1'];
        $media->save();

        $this->purge(['--force' => true])->assertSuccessful();

        $this->assertNotNull(MediaItem::find($media->id));
        $this->assertTrue(Storage::disk(MediaItem::disk())->exists($path));
    }

    // ---- what stays ------------------------------------------------------

    /**
     * The guarantee that matters most. Without these rows the university
     * cannot say why anything was removed, and an appeal has nothing to
     * appeal against.
     */
    public function test_moderation_records_survive(): void
    {
        $post = $this->makePost('post-1');

        ModerationEvent::create([
            'id' => (string) Str::uuid(),
            'user_id' => (string) $this->author->id,
            'content_type' => 'text',
            'source_feature' => 'feed',
            'content_id' => $post->id,
            'action' => 'blocked',
            'flagged' => true,
            'created_at' => now(),
        ]);

        $this->purge(['--force' => true])->assertSuccessful();

        $this->assertSame(1, ModerationEvent::count(),
            'The record of what was decided about a student must not be erased.');
    }

    public function test_stories_are_not_touched(): void
    {
        $this->makePost('post-1');
        Story::create([
            'id' => 'story-1',
            'author_id' => $this->author->id,
            'author_name' => 'Author',
            'text' => 'a story',
            'created_at' => now(),
        ]);

        $this->purge(['--force' => true])->assertSuccessful();

        $this->assertSame(1, Story::withoutGlobalScopes()->count());
    }

    public function test_accounts_are_not_touched(): void
    {
        $this->makePost('post-1');

        $this->purge(['--force' => true])->assertSuccessful();

        $this->assertNotNull(User::find($this->author->id));
    }

    // ---- the backup and the report ---------------------------------------

    public function test_a_backup_is_written_before_anything_is_deleted(): void
    {
        $dir = $this->backupDir;
        [, , $path] = $this->mediaBackedPost('post-1');

        PostComment::create([
            'id' => 'comment-1',
            'post_id' => 'post-1',
            'user_id' => $this->author->id,
            'text' => 'nice',
            'created_at' => now(),
        ]);

        $this->purge(['--force' => true])->assertSuccessful();

        $folders = glob($dir.'/social-timeline-*');
        $this->assertCount(1, $folders);
        $backup = $folders[0];

        foreach (['feed_posts', 'post_comments', 'post_likes', 'saved_posts',
            'media_items', 'manifest'] as $file) {
            $this->assertFileExists($backup.'/'.$file.'.json');
        }

        $posts = json_decode(file_get_contents($backup.'/feed_posts.json'), true);
        $this->assertCount(1, $posts);
        $this->assertSame('post-1', $posts[0]['id']);

        $manifest = json_decode(file_get_contents($backup.'/manifest.json'), true);
        $this->assertSame(1, $manifest['counts']['feed_posts']);
        $this->assertSame(1, $manifest['counts']['post_comments']);
        $this->assertSame(1, $manifest['counts']['media_files']);

        // The bytes themselves, not just a note that they existed.
        $this->assertCount(1, $manifest['media']);
        $this->assertSame($path, $manifest['media'][0]['stored_at']);
        $this->assertFileExists($backup.'/media/'.$manifest['media'][0]['backup_file']);

        // Tidy up after ourselves; this one is outside the faked disk.
        array_map('unlink', glob($backup.'/media/*'));
        array_map('unlink', glob($backup.'/*.json'));
        rmdir($backup.'/media');
        rmdir($backup);
        rmdir($dir);
    }

    public function test_the_purge_is_written_to_the_audit_trail(): void
    {
        $this->makePost('post-1');

        $this->purge(['--force' => true])->assertSuccessful();

        $this->assertDatabaseHas('admin_audit_log', [
            'action' => 'purge',
            'target_type' => 'social_timeline',
        ]);
    }

    public function test_an_empty_timeline_is_a_no_op(): void
    {
        $this->purge(['--force' => true])
            ->expectsOutputToContain('already empty')
            ->assertSuccessful();
    }
}
