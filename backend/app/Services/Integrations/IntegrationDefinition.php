<?php

namespace App\Services\Integrations;

use Closure;

/**
 * One integration, described once.
 *
 * The registry is the only place that knows an integration exists. Everything
 * else — the API, the Filament page, the tests — reads these descriptors, so
 * adding a provider is one entry here rather than edits in four files.
 *
 * `$configured` and `$tester` are closures rather than data because the
 * answer already lives somewhere else in the codebase (config/services.php,
 * app_settings, a service class). Re-deriving it here would give the panel a
 * second opinion that could disagree with the code that actually runs.
 */
final class IntegrationDefinition
{
    /**
     * @param  list<string>  $envKeys  Env var NAMES only — never values.
     * @param  Closure():bool  $configured
     * @param  (Closure():IntegrationTestResult)|null  $tester  null when the
     *                                                          provider cannot be reached without doing real work (e.g. FCM
     *                                                          needs a signed JWT); such an integration reports config-only
     *                                                          validation instead of pretending to have connected.
     * @param  (Closure():?string)|null  $secretHint  Masked tail of the stored
     *                                                credential, or null when there is nothing secret to show.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $description,
        public readonly array $envKeys,
        public readonly Closure $configured,
        public readonly ?Closure $tester = null,
        public readonly ?Closure $secretHint = null,
        /** Where an operator changes it: 'env' (deploy) or 'admin' (panel). */
        public readonly string $managedVia = 'env',
        /**
         * When set, the integration is deliberately switched off in the
         * product (not in use) and reported Disabled regardless of config —
         * e.g. WordPress. The architecture stays intact so it can be
         * re-enabled by clearing this.
         */
        public readonly ?string $builtinDisabledReason = null,
        /** True for a service that runs on ARUCAD infrastructure. */
        public readonly bool $selfHosted = false,
    ) {}

    public function isConfigured(): bool
    {
        return (bool) ($this->configured)();
    }

    public function isRemotelyTestable(): bool
    {
        return $this->tester !== null;
    }

    /**
     * Masked tail of the stored secret, e.g. "••••••••c1793".
     *
     * Never the value itself. Short secrets are masked whole rather than
     * revealing a meaningful fraction of them.
     */
    public function maskedSecret(): ?string
    {
        if ($this->secretHint === null) {
            return null;
        }
        $value = ($this->secretHint)();
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return self::mask($value);
    }

    public static function mask(string $value): string
    {
        $value = trim($value);
        if (mb_strlen($value) <= 8) {
            return str_repeat('•', 8);
        }

        return str_repeat('•', 8).mb_substr($value, -4);
    }
}
