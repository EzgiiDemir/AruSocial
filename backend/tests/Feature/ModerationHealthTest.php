<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The classifier's state has to be visible from outside.
 *
 * When it is down every photo and video upload correctly fails closed
 * with a 503 — which to a student looks identical to their content being
 * rejected. "Stories block everything" and "nobody started the service"
 * produced the same symptom, and there was no way to tell them apart
 * without reading server logs.
 */
class ModerationHealthTest extends TestCase
{
    use RefreshDatabase;

    private function withClassifier(?callable $fake): void
    {
        config([
            'moderation.image.enabled' => true,
            'moderation.image.base_url' => 'http://image-moderation.test',
            'moderation.image.policy_version' => 'image-v2-calibrated',
        ]);
        if ($fake !== null) {
            $fake();
        }
    }

    public function test_health_reports_a_working_classifier(): void
    {
        $this->withClassifier(fn () => Http::fake([
            'image-moderation.test/health' => Http::response([
                'ok' => true,
                'model' => 'Falconsai/nsfw_image_detection',
                'model_version' => '96cb0d03',
            ]),
        ]));

        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('data.moderation.image', 'ok')
            ->assertJsonPath('data.moderation.modelVersion', '96cb0d03')
            // The policy version belongs here too: the same model with
            // different thresholds is a different system.
            ->assertJsonPath('data.moderation.policyVersion', 'image-v2-calibrated');
    }

    /** The state that caused the confusion. */
    public function test_health_reports_an_unreachable_classifier(): void
    {
        $this->withClassifier(fn () => Http::fake([
            'image-moderation.test/*' => fn () => throw new ConnectionException('refused'),
        ]));

        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('data.moderation.image', 'unreachable');
    }

    /**
     * Up but unable to load its weights is a different fix from "not
     * running", so it gets a different word.
     */
    public function test_health_distinguishes_a_loaded_service_from_a_broken_model(): void
    {
        $this->withClassifier(fn () => Http::fake([
            'image-moderation.test/*' => Http::response(['ok' => false], 503),
        ]));

        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('data.moderation.image', 'model_not_loaded');
    }

    /** Not configured is a deployment choice, not a fault. */
    public function test_health_reports_disabled_rather_than_broken(): void
    {
        config(['moderation.image.enabled' => false]);

        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('data.moderation.image', 'disabled');
    }

    /**
     * Health must answer even when the classifier does not. An endpoint
     * that fails whenever a dependency fails cannot be used to find out
     * which dependency failed.
     */
    public function test_health_still_answers_when_the_classifier_is_down(): void
    {
        $this->withClassifier(fn () => Http::fake([
            'image-moderation.test/*' => fn () => throw new ConnectionException('refused'),
        ]));

        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.database', 'ok');
    }
}
