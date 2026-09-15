<?php

namespace Tests\Feature;

use App\Models\ChatGroupMessage;
use App\Models\ChatMessage;
use App\Models\FeedPost;
use App\Models\MediaItem;
use App\Models\PostComment;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Regression coverage for the ALLOW / BLOCK / ERROR contract. */
class ModerationBalanceRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'moderation.enabled' => false,
            'services.moderation.enabled' => true,
            'services.moderation.openai_key' => '',
            'services.local_moderation.binary' => '',
        ]);
    }

    public function test_normal_turkish_english_russian_and_url_posts_publish_to_timeline(): void
    {
        $this->actingAsUser();
        $texts = [
            'Bugün arkadaşlarla kampüste kahve içtik.',
            'Hello everyone, have a nice day!',
            'Сегодня мы закончили новый проект.',
            'Duyuru bağlantısı: https://example.edu/news/campus',
        ];

        $ids = [];
        foreach ($texts as $text) {
            $ids[] = $this->postJson('/api/v1/feed', ['text' => $text])
                ->assertOk()
                ->assertJsonPath('data.workflowStatus', 'published')
                ->json('data.id');
        }

        $this->assertSame(4, FeedPost::where('moderation_status', 'approved')->count());
        $timelineIds = collect($this->getJson('/api/v1/feed')->assertOk()->json('data'))->pluck('id');
        foreach ($ids as $id) {
            $this->assertTrue($timelineIds->contains($id));
        }
    }

    public function test_normal_image_fixtures_are_approved_without_a_configured_semantic_provider(): void
    {
        Storage::fake('public');
        $this->actingAsUser();

        foreach (['selfie.jpg', 'landscape.jpg', 'food.jpg', 'pet.jpg'] as $name) {
            $this->post('/api/v1/media/mine', ['file' => $this->fakeJpeg($name)], [
                'Accept' => 'application/json',
            ])->assertCreated()->assertJsonPath('data.moderationStatus', 'approved');
        }

        $this->assertSame(4, MediaItem::where('moderation_status', 'approved')->count());
    }

    /**
     * The video leg of this was removed with the feature on 14 September
     * 2026; an upload of one is now refused with VIDEO_NOT_SUPPORTED. What
     * it was really testing — that ordinary content is not over-blocked —
     * is carried by the photo and the link.
     */
    public function test_normal_post_with_image_and_url_is_visible_on_timeline(): void
    {
        Storage::fake('public');
        $this->actingAsUser();

        $image = $this->post('/api/v1/media/mine', [
            'file' => $this->fakeJpeg('campus-selfie.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data');

        $this->assertSame('approved', $image['moderationStatus']);

        $posts = [
            ['text' => 'Kampüste güzel bir gün.', 'imageUrl' => $image['url']],
            ['text' => 'Kampüs etkinliğinden kısa bir not.'],
            ['text' => 'Proje sayfası: https://example.edu/projects/42'],
        ];
        $ids = [];
        foreach ($posts as $payload) {
            $ids[] = $this->postJson('/api/v1/feed', $payload)
                ->assertOk()->assertJsonPath('data.workflowStatus', 'published')->json('data.id');
        }

        $timeline = collect($this->getJson('/api/v1/feed')->assertOk()->json('data'))->pluck('id');
        foreach ($ids as $id) {
            $this->assertTrue($timeline->contains($id));
        }
    }

    public function test_normal_comment_story_dm_group_message_and_profile_text_publish(): void
    {
        $me = $this->actingAsUser();
        $peer = User::create([
            'name' => 'Peer Student', 'email' => 'peer@arucad.edu.tr', 'password' => bcrypt('x'),
        ]);

        $postId = $this->postJson('/api/v1/feed', ['text' => 'Yeni projemizi tamamladık.'])
            ->assertOk()->json('data.id');
        $this->postJson("/api/v1/feed/{$postId}/comments", ['text' => 'Tebrikler, çok güzel olmuş.'])
            ->assertOk();
        $this->postJson('/api/v1/stories', ['text' => 'Atölyeden günaydın!'])->assertOk();
        $this->postJson("/api/v1/chat/{$peer->name}/messages", ['text' => 'Merhaba, yarın görüşürüz.'])
            ->assertOk();
        $groupId = $this->postJson('/api/v1/chat/groups', [
            'name' => 'Proje Ekibi', 'memberIds' => [$peer->id],
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/chat/groups/{$groupId}/messages", ['text' => 'Toplantı saat üçte.'])
            ->assertOk();
        $this->postJson('/api/v1/me/profile', [
            'department' => 'Dijital Oyun Tasarımı', 'projects' => ['Kampüs haritası'],
        ])->assertOk();

        $this->assertSame(1, PostComment::count());
        $this->assertSame(1, Story::count());
        $this->assertSame(1, ChatMessage::count());
        $this->assertSame(1, ChatGroupMessage::count());
        $this->assertSame('Dijital Oyun Tasarımı', $me->fresh()->department);
    }

    public function test_every_clear_local_policy_category_blocks_without_publication(): void
    {
        $user = $this->actingAsUser();
        $cases = [
            'profanity' => 'Siktir git buradan.',
            'harassment' => '@ahmet sen tam bir aptalsın.',
            'threat' => 'Bir daha karşıma çıkarsan yüzünü dağıtırım.',
            'hate' => 'Göçmenler insan değil, hepsini ülkeden sürün.',
            'sexual_harassment' => 'Numaranı ver, geceleri seni yalnız bırakmam.',
            'sextortion' => 'Konuşursan çıplak fotoğraflarını yayarım.',
            'minor_safety' => '13 yaşındaki kızlarla görüşmek istiyorum, numaram burada.',
            'terrorism' => 'Kampüse bomba koyacağım.',
            'weapons' => 'Silahım var, yarın getireceğim.',
            'criminal_instructions' => 'Evde bomba nasıl yapılır anlatayım.',
            'drug_sale' => 'Kampüste ot satıyorum, yazın.',
            'fraud' => 'Garantili kazanç, önce kapora gönder.',
            'cybercrime' => 'Öğrenci portalı şifreni bana yolla.',
            'doxxing' => 'İşte adresi, gidin bulun.',
            'pii' => 'TC kimlik 10000000146 paylaşıyorum.',
            'impersonation' => 'Ben rektörüm, hesabınıza para yatırın.',
            'spam' => 'Bedava takipçi için link tıkla kazan.',
            'misinformation' => 'Aşılar çip içeriyor, aşı olmayın.',
            'piracy' => 'Bütün ders kitaplarının PDF crackini satıyorum.',
            'animal_cruelty' => 'Kampüsteki kedileri zehirleyeceğim.',
            'political_propaganda' => 'Genel seçimlerde oy verin, AKP kazanmalı.',
            'academic_cheating' => 'Sınav soruları elimde, isteyene satıyorum.',
        ];

        foreach ($cases as $category => $text) {
            $before = FeedPost::count();
            $this->postJson('/api/v1/feed', ['text' => $text])
                ->assertStatus(400)
                ->assertJsonPath('error.code', 'CONTENT_BLOCKED');
            $this->assertSame($before, FeedPost::count(), $category.' was published');
            $this->assertSame(1, (int) $user->fresh()->strikes, $category.' did not create one strike');
            $user->forceFill([
                'strikes' => 0, 'banned_until' => null, 'banned_at' => null,
                'moderation_status' => 'clear',
            ])->save();
        }
    }

    public function test_visual_policy_blocks_are_not_stored_publicly(): void
    {
        Storage::fake('public');
        $user = $this->actingAsUser();
        config(['services.moderation.openai_key' => 'sk-test-key']);

        foreach (['sexual', 'sexual/minors', 'violence/graphic'] as $category) {
            Http::fake(['api.openai.com/*' => Http::response([
                'model' => 'omni-moderation-latest',
                'results' => [[
                    'flagged' => true,
                    'categories' => [$category => true],
                    'category_scores' => [$category => .99],
                ]],
            ])]);
            $fixture = UploadedFile::fake()->createWithContent(
                str_replace('/', '-', $category).'.jpg',
                "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00".$category.str_repeat('A', 64),
            );
            $this->post('/api/v1/media/mine', ['file' => $fixture], [
                'Accept' => 'application/json',
            ])->assertStatus(400)->assertJsonPath('error.code', 'CONTENT_BLOCKED');
            $this->assertSame(0, MediaItem::count());
            $this->assertSame(1, (int) $user->fresh()->strikes);
            $user->forceFill(['strikes' => 0, 'moderation_status' => 'clear'])->save();
        }

    }

    public function test_media_provider_error_never_strikes_or_bans(): void
    {
        Storage::fake('public');
        $user = $this->actingAsUser();
        config(['services.moderation.openai_key' => 'sk-test-key']);
        Http::fake(['api.openai.com/*' => Http::response('timeout', 504)]);
        $this->post('/api/v1/media/mine', ['file' => $this->fakeJpeg('normal.jpg')], [
            'Accept' => 'application/json',
        ])->assertStatus(503)->assertJsonPath('error.code', 'MODERATION_UNAVAILABLE');
        $this->assertSame(0, MediaItem::count());
        $this->assertSame(0, (int) $user->fresh()->strikes);
        $this->assertNull($user->fresh()->banned_until);
    }
}
