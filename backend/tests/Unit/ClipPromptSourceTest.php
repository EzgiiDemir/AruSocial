<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The calibration probe must read the service's prompts, not its own copy.
 *
 * `probe_clip.py` is what every CLIP threshold in config/moderation.php
 * was measured with. It used to define its own `RISK_PROMPTS` and
 * `BENIGN_PROMPTS`, and they drifted: the service grew eleven
 * artistic-media prompts and a whole everyday-clothing set that the probe
 * never saw. So the tool used to choose thresholds was measuring a
 * classifier that does not exist, and its numbers — including the
 * "safe-set noise" figures quoted in MODERATION_COVERAGE.md — described
 * a copy. Nothing failed. The measurements were simply about something
 * else.
 *
 * There is no Python test harness in this repository, and adding one for
 * a single invariant is more machinery than the invariant is worth. This
 * is a monorepo and the file is right there, so the check lives here:
 * cheap, runs with everything else, and fails the moment somebody pastes
 * the lists back in.
 */
class ClipPromptSourceTest extends TestCase
{
    private function probe(): string
    {
        // No booted application here, so no base_path(): tests/Unit -> backend -> repo root.
        $path = __DIR__.'/../../../image-moderation-service/probe_clip.py';

        if (! is_file($path)) {
            $this->markTestSkipped('The moderation service is not checked out here.');
        }

        return (string) file_get_contents($path);
    }

    public function test_the_probe_imports_the_prompts_it_measures(): void
    {
        $source = $this->probe();

        $this->assertMatchesRegularExpression(
            '/from\s+app\.clip_classifier\s+import\s+[^\n]*BENIGN_PROMPTS/',
            $source,
            'probe_clip.py must import BENIGN_PROMPTS from the service.',
        );
        $this->assertMatchesRegularExpression(
            '/from\s+app\.clip_classifier\s+import\s+[^\n]*RISK_PROMPTS/',
            $source,
            'probe_clip.py must import RISK_PROMPTS from the service.',
        );
    }

    public function test_the_probe_does_not_redefine_them(): void
    {
        $source = $this->probe();

        foreach (['RISK_PROMPTS', 'BENIGN_PROMPTS'] as $name) {
            $this->assertDoesNotMatchRegularExpression(
                '/^'.$name.'\s*[:=]/m',
                $source,
                "probe_clip.py defines its own {$name}. Two prompt lists that "
                .'must agree are one prompt list — import it from the service, '
                .'or every threshold measured with this probe describes a copy.',
            );
        }
    }
}
