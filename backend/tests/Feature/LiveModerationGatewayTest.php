<?php

namespace Tests\Feature;

use App\Models\FeedPost;
use App\Models\MediaItem;
use App\Services\Moderation\VideoModerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Opt-in end-to-end test against a running moderation-service process.
 *
 * RUN_LIVE_MODERATION_TESTS=1 LIVE_MODERATION_URL=http://127.0.0.1:18080
 * php artisan test --filter=LiveModerationGatewayTest
 *
 * This targets the moderation *gateway* (`moderation.base_url`), which is
 * a separate service from the self-hosted classifier on 8801 — pointing
 * it at 8801 will not work. Without that gateway running every upload
 * here returns 503, which is the pipeline failing closed correctly rather
 * than a bug in the test.
 *
 * `LIVE_VIDEO_FIXTURE` is optional: a benign clip is generated with
 * ffmpeg when it is unset. It used to be required, and the test aborted
 * without it — so the one case that exercises the real gateway end to end
 * never ran unless somebody had an MP4 to hand.
 */
class LiveModerationGatewayTest extends TestCase
{
    use RefreshDatabase;

    /** Cleaned up in tearDown when this test generated its own clip. */
    private ?string $generatedClip = null;

    protected function tearDown(): void
    {
        if ($this->generatedClip !== null) {
            @unlink($this->generatedClip);
        }
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RUN_LIVE_MODERATION_TESTS') !== '1') {
            $this->markTestSkipped('Live moderation gateway was not requested.');
        }

        config([
            'moderation.enabled' => true,
            'moderation.base_url' => getenv('LIVE_MODERATION_URL') ?: 'http://127.0.0.1:18080',
            'moderation.timeout' => 15,
            'services.moderation.openai_key' => '',
            'services.local_moderation.binary' => '',
        ]);
        Storage::fake(MediaItem::disk());
    }

    /**
     * A short, plainly benign clip for the "normal upload is approved"
     * half of this test.
     *
     * Generated rather than required. `LIVE_VIDEO_FIXTURE` still wins
     * when it is set, but without this the whole test aborted on a
     * missing env var — so the one test that exercises the real gateway
     * end to end never ran unless somebody had put an MP4 somewhere and
     * remembered to point at it.
     */
    private function benignClip(): string
    {
        if (($supplied = (string) getenv('LIVE_VIDEO_FIXTURE')) !== '' && is_file($supplied)) {
            return $supplied;
        }

        if (($path = getenv('FFMPEG_PATH')) !== false && $path !== '') {
            config(['services.moderation.ffmpeg_path' => $path]);
        }
        if (! (new VideoModerator)->ffmpegAvailable()) {
            $this->markTestSkipped('Set LIVE_VIDEO_FIXTURE, or install ffmpeg so one can be generated.');
        }

        $clip = sys_get_temp_dir().DIRECTORY_SEPARATOR.'arucad-benign-'.bin2hex(random_bytes(4)).'.mp4';
        $process = new Process([
            config('services.moderation.ffmpeg_path', 'ffmpeg'),
            '-y', '-loglevel', 'error',
            '-f', 'lavfi', '-i', 'color=c=steelblue:s=320x240:r=10',
            '-t', '3', '-pix_fmt', 'yuv420p', $clip,
        ]);
        $process->setTimeout(60);
        $process->run();

        $this->assertFileExists($clip, 'ffmpeg could not generate the benign clip.');
        $this->generatedClip = $clip;

        return $clip;
    }

    public function test_real_gateway_allows_normal_post_image_video_url_and_blocks_violations(): void
    {
        $videoPath = $this->benignClip();
        $user = $this->actingAsUser();

        $imagePath = base_path('../frontend/web/favicon.png');
        $this->assertFileExists($imagePath);
        $image = $this->post('/api/v1/media/mine', [
            'file' => new UploadedFile($imagePath, 'normal-campus.png', 'image/png', null, true),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.moderationStatus', 'approved')
            ->json('data');

        $video = $this->post('/api/v1/media/mine', [
            'file' => new UploadedFile($videoPath, 'normal-campus.mp4', 'video/mp4', null, true),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.moderationStatus', 'approved')
            ->json('data');

        $payloads = [
            ['text' => 'Bugün kampüste kahve içtik.'],
            ['text' => 'Normal kampüs fotoğrafı.', 'imageUrl' => $image['url']],
            ['text' => 'Normal kampüs videosu.', 'imageUrl' => $video['url']],
            ['text' => 'Normal bağlantı: https://example.edu/campus'],
        ];
        $allowedIds = [];
        foreach ($payloads as $payload) {
            $allowedIds[] = $this->postJson('/api/v1/feed', $payload)
                ->assertOk()
                ->assertJsonPath('data.workflowStatus', 'published')
                ->json('data.id');
        }

        $timeline = collect($this->getJson('/api/v1/feed')->assertOk()->json('data'))->pluck('id');
        foreach ($allowedIds as $id) {
            $this->assertTrue($timeline->contains($id));
        }

        $this->postJson('/api/v1/feed', ['text' => 'Kampüse bomba koyacağım.'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'CONTENT_BLOCKED');
        $this->postJson('/api/v1/feed', ['text' => 'Bak: http://malware.test/steal'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'CONTENT_BLOCKED');

        $this->assertSame(4, FeedPost::count());
        $this->assertSame(2, MediaItem::where('moderation_status', 'approved')->count());
        $this->assertSame(2, (int) $user->fresh()->strikes);
        $this->assertNull($user->fresh()->banned_at);
        $this->assertNull($user->fresh()->banned_until);
    }
}
