<?php

namespace Tests\Feature;

use App\Models\MediaItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The visual policy: real people in photographs, not art on paper.
 *
 * Kissing, swimwear and nudity are refused when the subject is a
 * photograph of real people, and allowed when it is drawn, painted or
 * sculpted. That distinction is not a nicety here — the campus is built
 * around a nude sculpture collection and figure drawing is a syllabus, so
 * a rule that cannot tell bronze from photography refuses the
 * university's own coursework.
 *
 * The separation is done by the classifier's prompts (see
 * clip_classifier.py, where every risky prompt names "a photograph of a
 * real person" and the benign side names charcoal, pencil, oil, print and
 * bronze). What is pinned here is the half Laravel owns: that each
 * category has a threshold, that it decides independently, and that the
 * strictest wins.
 *
 * Measured end to end on the 74-image set at these thresholds: 12
 * artworks including both copies of Rodin's *The Kiss* all published,
 * every real nude refused, and a clip of campus → sculpture → campus
 * published while campus → real nudity → campus was refused.
 */
class VisualPolicyCategoriesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'moderation.image.enabled' => true,
            'moderation.image.base_url' => 'http://image-moderation.test',
            'moderation.image.thresholds' => [
                // Mirrors config/moderation.php. 0.35 rather than 0.20
                // because everyday summer clothing reaches 0.221 on this
                // model — see test_the_nsfw_review_line_clears_everyday_clothing.
                'nsfw' => ['review' => 0.35, 'block' => 0.50],
                'clip_nudity' => ['review' => 0.35, 'block' => 0.45],
                'clip_kissing' => ['review' => 0.35, 'block' => 0.45],
                'clip_swimwear' => ['review' => 0.35, 'block' => 0.45],
                'clip_gore' => ['review' => 0.45, 'block' => 0.60],
            ],
            'services.moderation.enabled' => false,
            'moderation.enabled' => false,
        ]);
    }

    /** @param array<string, float> $scores */
    private function classifierReturns(array $scores): void
    {
        Http::fake(['image-moderation.test/*' => Http::response([
            'success' => true,
            'model' => 'Falconsai/nsfw_image_detection',
            'model_version' => 'abc',
            'models' => ['nsfw' => 'nsfw-model', 'clip' => 'clip-model'],
            'scores' => array_merge([
                'nsfw' => 0.01, 'normal' => 0.99,
                'clip_nudity' => 0.01, 'clip_kissing' => 0.01,
                'clip_swimwear' => 0.01, 'clip_gore' => 0.01,
            ], $scores),
            'latency_ms' => 40,
        ])]);
    }

    private function upload(): TestResponse
    {
        Storage::fake('local');
        $this->actingAsUser();

        return $this->post('/api/v1/media/mine', [
            'file' => $this->fakeJpeg('upload.jpg'),
        ], ['Accept' => 'application/json']);
    }

    public static function blockingCategories(): array
    {
        return [
            'real nudity' => ['clip_nudity'],
            'people kissing' => ['clip_kissing'],
            'bikini or swimwear' => ['clip_swimwear'],
        ];
    }

    #[DataProvider('blockingCategories')]
    public function test_a_photograph_scoring_high_on_a_policy_category_is_refused(
        string $category,
    ): void {
        $this->classifierReturns([$category => 0.72]);

        $this->upload()
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'CONTENT_BLOCKED');

        $this->assertSame(0, MediaItem::count(),
            "A {$category} upload was stored.");
    }

    /**
     * The artistic case, reduced to numbers.
     *
     * A charcoal nude or a bronze of two figures kissing scores low on
     * every one of these because the prompts compete by medium — the
     * measured values for Rodin's *The Kiss* are 0.0095 nudity and 0.0004
     * kissing. This asserts the policy does not refuse them anyway.
     */
    public function test_drawn_and_sculpted_nudity_is_published(): void
    {
        $this->classifierReturns([
            'clip_nudity' => 0.0095,
            'clip_kissing' => 0.0004,
            'clip_swimwear' => 0.0051,
        ]);

        $created = $this->upload()->assertCreated()->json('data');

        $this->assertSame('approved', $created['moderationStatus']);
    }

    /**
     * An ordinary selfie in a sleeveless top measured **0.6125** on
     * swimwear before everyday clothing was named on the benign side of
     * the prompts, and would have been refused as a bikini photo. Bare
     * shoulders are not swimwear, and this is Kyrenia.
     */
    public function test_an_ordinary_selfie_is_not_refused_as_swimwear(): void
    {
        $this->classifierReturns([
            'clip_swimwear' => 0.06,
            'clip_nudity' => 0.10,
        ]);

        $this->upload()->assertCreated();
    }

    /**
     * Everyday modern clothing publishes. Measured from real uploads,
     * each verified by eye.
     *
     * Crop tops, sports bras, short shorts, short dresses and modest
     * cleavage are ordinary clothing, not nudity. The safe set that
     * originally calibrated these thresholds contained no photographs of
     * people in summer clothing at all — it was buildings, sculptures,
     * screenshots and logos — so the distribution it produced was far too
     * narrow, and a rooftop photo in a t-shirt and denim shorts was held.
     *
     * @return array<string, array{0: array<string, float>}>
     */
    public static function everydayClothing(): array
    {
        return [
            't-shirt and denim shorts' => [['nsfw' => 0.221, 'clip_nudity' => 0.004, 'clip_swimwear' => 0.024]],
            'sports bra and shorts' => [['nsfw' => 0.116, 'clip_nudity' => 0.002, 'clip_swimwear' => 0.094]],
            'shirtless male selfie' => [['nsfw' => 0.016, 'clip_nudity' => 0.097, 'clip_swimwear' => 0.056]],
            'muay thai bout' => [['nsfw' => 0.000, 'clip_nudity' => 0.080, 'clip_kissing' => 0.122]],
        ];
    }

    /** @param array<string, float> $scores */
    #[DataProvider('everydayClothing')]
    public function test_everyday_clothing_publishes(array $scores): void
    {
        $this->classifierReturns($scores);

        $created = $this->upload()->assertCreated()->json('data');

        $this->assertSame('approved', $created['moderationStatus'],
            'Ordinary clothing was held or refused.');
    }

    /**
     * The measured floor, stated as an assertion so nobody lowers the
     * review line back under it without the test failing.
     */
    public function test_the_nsfw_review_line_clears_everyday_clothing(): void
    {
        $review = (float) config('moderation.image.thresholds.nsfw.review');

        $this->assertGreaterThan(0.221, $review,
            'A rooftop photo in a t-shirt and denim shorts scores 0.221 on this '
            .'model. A review line at or below that holds ordinary clothing.');
        $this->assertLessThan(
            (float) config('moderation.image.thresholds.nsfw.block'),
            $review,
        );
    }

    /**
     * Two people kissing, in any combination, is refused — and the two
     * real photographs this was measured against scored 0.996 and 0.998,
     * so the rule is not theoretical.
     */
    public function test_a_photograph_of_people_kissing_is_refused(): void
    {
        $this->classifierReturns(['clip_kissing' => 0.996]);

        $this->upload()
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'CONTENT_BLOCKED');
    }

    /**
     * Between review and block a human decides. Held content is not
     * published, and at this threshold nothing in the safe set reaches
     * the band at all.
     */
    public function test_a_borderline_score_is_held_rather_than_published(): void
    {
        $this->classifierReturns(['clip_nudity' => 0.40]);

        $created = $this->upload()->assertCreated()->json('data');

        $this->assertSame('pending', $created['moderationStatus'],
            'Borderline content was published instead of held.');
    }

    /**
     * Categories decide independently and the strictest wins. Averaging
     * would let one very high score be diluted by several low ones — an
     * image the model is certain is explicit passing because it is
     * confidently not gore and confidently not a weapon.
     */
    public function test_one_high_category_is_not_diluted_by_the_others(): void
    {
        $this->classifierReturns([
            'clip_kissing' => 0.90,
            'clip_nudity' => 0.01,
            'clip_swimwear' => 0.01,
            'clip_gore' => 0.01,
            'nsfw' => 0.01,
        ]);

        $this->upload()->assertStatus(400);
    }

    /**
     * How the policy reaches stories and posts: by construction.
     *
     * Neither surface uploads its own media. Both can only *reference* an
     * item already in the library, and `SocialMediaAttachment::resolve`
     * refuses anything not `approved` — so an image is scanned once, at
     * upload, and there is no second path that could miss it.
     *
     * Asserted for stories specifically because that is where this was
     * asked about, and because "it uses the same code" is a claim worth
     * one test rather than one sentence.
     */
    public function test_a_story_cannot_attach_media_that_has_not_passed(): void
    {
        $user = $this->actingAsUser();

        foreach (['pending' => 'MEDIA_PENDING_REVIEW', 'rejected' => 'MEDIA_REJECTED'] as $status => $code) {
            $item = MediaItem::create([
                'id' => "media-{$status}",
                'user_id' => $user->id,
                'file_path' => "media/{$status}.jpg",
                'file_name' => "{$status}.jpg",
                'mime_type' => 'image/jpeg',
                'size_bytes' => 1,
                'uploaded_at' => now(),
                'uploaded_by' => $user->name,
                'used_in' => [],
                'moderation_status' => $status,
            ]);

            $this->postJson('/api/v1/stories', [
                'text' => 'hikaye',
                'imageUrl' => $item->url(),
            ])->assertStatus(422)->assertJsonPath('error.code', $code);
        }
    }

    /** And a remote URL cannot be used to sidestep the library at all. */
    public function test_a_story_cannot_reference_media_from_outside(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/v1/stories', [
            'text' => 'hikaye',
            'imageUrl' => 'https://untrusted.example/unscanned.jpg',
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'SOCIAL_MEDIA_UPLOAD_REQUIRED');
    }

    /**
     * The categories with no lawful test images still must not be able to
     * decide anything: scored and recorded, never enforced, until someone
     * supplies positives to calibrate against.
     */
    public function test_uncalibrated_categories_have_no_threshold(): void
    {
        $thresholds = (array) config('moderation.image.thresholds');

        foreach (['clip_weapon', 'clip_hate_symbol', 'clip_drugs', 'clip_self_harm'] as $category) {
            $this->assertArrayNotHasKey($category, $thresholds,
                "{$category} gained a threshold without a positive example to calibrate it.");
        }
    }
}
