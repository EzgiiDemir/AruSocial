<?php

namespace Tests\Feature;

use App\Models\FeedPost;
use App\Models\ModerationEvent;
use App\Services\Moderation\PolicyLexicon;
use App\Services\Moderation\TextNormalizer;
use App\Services\Moderation\TextPolicyEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A student saying they are in crisis must be met with help, every time.
 *
 * This path is deliberately not punitive and deliberately not censored:
 * the post publishes, the author is shown ARUCAD's counselling contact
 * and the emergency number, no strike is recorded, and a moderator sees a
 * review event. Silencing someone who says this teaches the people most
 * at risk that saying it out loud costs them.
 *
 * The regression this pins was measured, not imagined. A support decision
 * recorded while the semantic provider was down was stamped
 * `decided_by=unavailable`, which the idempotency replay could not tell
 * apart from "nothing inspected this" — so posting the same words a
 * second time within ten minutes answered with a MODERATION_UNAVAILABLE
 * server error instead of the counselling contact. Re-posting is exactly
 * what someone in distress does.
 */
class SelfHarmSupportPathTest extends TestCase
{
    use RefreshDatabase;

    private const CRISIS_TR = 'Artik dayanamiyorum, bu gece her seyi bitirecegim, kimse aramasin';

    private const CRISIS_EN = 'I cannot do this anymore, tonight I am going to end my life, please do not look for me';

    protected function setUp(): void
    {
        parent::setUp();
        // The provider being unavailable is the condition under which the
        // bug appeared, so it is the condition under test.
        config([
            'services.moderation.enabled' => false,
            'services.moderation.openai_key' => '',
            'moderation.enabled' => false,
            'moderation.image.enabled' => false,
        ]);
    }

    public function test_a_crisis_post_publishes_with_support_rather_than_being_refused(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/v1/feed', ['text' => self::CRISIS_TR])
            ->assertSuccessful()
            ->assertJsonPath('meta.moderation.status', 'support');
    }

    /** The offer is worthless if the contact details are not in it. */
    public function test_the_support_message_names_where_to_get_help(): void
    {
        $this->actingAsUser();

        $message = $this->postJson('/api/v1/feed', ['text' => self::CRISIS_TR])
            ->json('meta.moderation.message');

        $this->assertStringContainsString('psikolojik.danismanlik@arucad.edu.tr', (string) $message);
        $this->assertStringContainsString('112', (string) $message);
    }

    /** The regression itself. */
    public function test_reposting_the_same_words_still_offers_support(): void
    {
        $this->actingAsUser();

        foreach ([1, 2, 3] as $attempt) {
            $this->postJson('/api/v1/feed', ['text' => self::CRISIS_EN])
                ->assertSuccessful()
                ->assertJsonPath('meta.moderation.status', 'support',
                    "Attempt {$attempt} withdrew the offer of support.");
        }
    }

    /**
     * Never punitive. A strike here would mean the student is worse off
     * for having said it.
     */
    public function test_a_crisis_post_costs_no_strike_and_no_ban(): void
    {
        $user = $this->actingAsUser();
        config(['moderation.enforcement.enabled' => true]);

        $this->postJson('/api/v1/feed', ['text' => self::CRISIS_TR])->assertSuccessful();

        $user->refresh();
        $this->assertSame(0, (int) $user->strikes);
        $this->assertNull($user->banned_at);
        $this->assertNull($user->banned_until);
    }

    /** A moderator has to be able to see it, or "reaches a human" is a lie. */
    public function test_a_review_event_is_recorded_for_a_moderator(): void
    {
        $user = $this->actingAsUser();

        $this->postJson('/api/v1/feed', ['text' => self::CRISIS_TR])->assertSuccessful();

        $event = ModerationEvent::where('user_id', $user->id)
            ->where('action', ModerationEvent::ACTION_REVIEW)
            ->latest('created_at')->first();

        $this->assertNotNull($event, 'No review event; nobody would ever see this.');
        $this->assertSame('self_harm', $event->decided_by);
    }

    public function test_the_post_is_actually_visible_not_quietly_hidden(): void
    {
        $this->actingAsUser();

        $id = $this->postJson('/api/v1/feed', ['text' => self::CRISIS_TR])->json('data.id');

        $this->assertSame(1, FeedPost::where('id', $id)->count(),
            'The post was hidden, which is the outcome this path exists to avoid.');
    }

    /**
     * The semantic layer routes crisis to support, never to a refusal.
     *
     * SELF has a `support` threshold and deliberately no `block` one.
     * Held-out testing found crisis posts the lexicon did not match — the
     * semantic margin detected them and, before this, did nothing with
     * the detection, because a category with no `block` threshold was
     * simply skipped.
     */
    public function test_the_semantic_layer_sends_crisis_to_support_not_refusal(): void
    {
        config([
            'moderation.text.enabled' => true,
            'moderation.text.base_url' => 'http://text-moderation.test',
            'moderation.text.thresholds' => [
                'SELF' => ['support' => 0.15],
                'THR' => ['block' => 0.15],
            ],
        ]);
        Http::fake(['text-moderation.test/*' => Http::response([
            'success' => true, 'model' => 'm', 'model_version' => 'v',
            'margins' => ['SELF' => 0.42, 'THR' => -0.30], 'latency_ms' => 20,
        ])]);
        $this->actingAsUser();

        // Text the lexicon does not match, so only the semantic layer can
        // have decided this.
        $this->postJson('/api/v1/feed', ['text' => 'Bu donem her sey cok agir geliyor bana'])
            ->assertSuccessful()
            ->assertJsonPath('meta.moderation.status', 'support');
    }

    /** A SELF margin must never be able to refuse a post. */
    public function test_a_high_self_margin_never_blocks(): void
    {
        config([
            'moderation.text.enabled' => true,
            'moderation.text.base_url' => 'http://text-moderation.test',
            'moderation.text.thresholds' => ['SELF' => ['support' => 0.15]],
        ]);
        Http::fake(['text-moderation.test/*' => Http::response([
            'success' => true, 'model' => 'm', 'model_version' => 'v',
            'margins' => ['SELF' => 0.99], 'latency_ms' => 20,
        ])]);
        $this->actingAsUser();

        $this->postJson('/api/v1/feed', ['text' => 'zor bir gun gecirdim bugun'])
            ->assertSuccessful()
            ->assertJsonPath('meta.moderation.status', 'support');
    }

    /**
     * Every phrase rule must map to a verdict.
     *
     * `decideFromPhrase` ends in `default => null`, so a rule carrying a
     * `kind` nobody wired up matches, decides nothing, and the content
     * publishes. It looks exactly like a working rule in the lexicon. Two
     * new rules were added that way during this work and silently did
     * nothing until measured.
     */
    public function test_every_phrase_rule_kind_reaches_a_decision(): void
    {
        $engine = new TextPolicyEngine;
        $decide = new \ReflectionMethod($engine, 'decideFromPhrase');
        $decide->setAccessible(true);

        $unmapped = [];
        foreach (PolicyLexicon::PHRASE_RULES as $rule) {
            $hit = [
                'kind' => $rule['kind'],
                'labels' => $rule['labels'],
                'severity' => $rule['severity'],
                'phrase' => $rule['phrases'][0] ?? 'x',
            ];
            $normalized = (new TextNormalizer)->normalize('placeholder');
            if ($decide->invoke($engine, $hit, $normalized, true, false) === null) {
                $unmapped[] = $rule['kind'];
            }
        }

        $this->assertSame([], array_values(array_unique($unmapped)),
            'These phrase-rule kinds match text and then decide nothing: '
            .implode(', ', array_unique($unmapped)));
    }
}
