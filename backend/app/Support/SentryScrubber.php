<?php

namespace App\Support;

use App\Models\AppSetting;
use Sentry\Event;
use Sentry\EventHint;
use Throwable;

// Sentry's own send_default_pii=false (config/sentry.php) already strips
// Authorization/Cookie/etc. headers before this runs (see
// vendor/sentry/sentry/src/Integration/RequestIntegration.php). This class
// covers what that doesn't: request *body* fields (password, apiKey,
// wordpress.apiToken, ...) and literal secret values that could otherwise
// leak into an exception message or breadcrumb — the same failure mode
// EmailService::redactSecrets() guards against for email_logs.
class SentryScrubber
{
    /**
     * Key fragments that mark a request/extra field as sensitive, matched
     * case-insensitively after stripping "-"/"_" (so api_key, apiKey and
     * api-key all match "apikey").
     *
     * @var string[]
     */
    private const SENSITIVE_KEY_FRAGMENTS = [
        'password',
        'passwd',
        'secret',
        'token',
        'apikey',
        'privatekey',
        'authorization',
        'cookie',
    ];

    public static function scrubEvent(Event $event, ?EventHint $hint = null): ?Event
    {
        $request = $event->getRequest();
        foreach (['data', 'cookies', 'headers'] as $key) {
            if (isset($request[$key]) && is_array($request[$key])) {
                $request[$key] = self::redactArray($request[$key]);
            }
        }
        $event->setRequest($request);

        $event->setExtra(self::redactArray($event->getExtra()));

        $secrets = self::knownSecretValues();
        if ($secrets !== []) {
            $exceptions = $event->getExceptions();
            foreach ($exceptions as $exception) {
                $exception->setValue(self::redactValues($exception->getValue(), $secrets));
            }
            $event->setExceptions($exceptions);

            if ($event->getMessage() !== null) {
                $event->setMessage(self::redactValues($event->getMessage(), $secrets));
            }
        }

        return $event;
    }

    /**
     * Best-effort short git commit hash for the `release` config value —
     * not a new deploy/version pipeline, just what's already on disk.
     */
    public static function gitReleaseHash(): ?string
    {
        $gitDir = base_path('.git');
        if (! is_dir($gitDir) || ! function_exists('shell_exec')) {
            return null;
        }

        try {
            $hash = trim((string) @shell_exec('git rev-parse --short HEAD 2>&1'));
        } catch (Throwable) {
            return null;
        }

        return preg_match('/^[0-9a-f]{4,40}$/i', $hash) === 1 ? $hash : null;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private static function redactArray(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            // Sensitivity is checked before recursing: a header like
            // Authorization: ["Bearer ..."] is itself an array, and must be
            // wholly redacted rather than have its harmless numeric-index
            // sub-key inspected instead of the real (outer) field name.
            if (self::isSensitiveKey((string) $key)) {
                $result[$key] = '[redacted]';

                continue;
            }
            $result[$key] = is_array($value) ? self::redactArray($value) : $value;
        }

        return $result;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', '_'], '', $key));
        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /** @param  string[]  $secrets */
    private static function redactValues(string $text, array $secrets): string
    {
        return str_replace($secrets, '[redacted]', $text);
    }

    /**
     * Literal secret values that must never appear in an exception message
     * or breadcrumb — mirrors EmailService::redactSecrets() but also covers
     * the other server-side-only credentials in this app.
     *
     * @return string[]
     */
    private static function knownSecretValues(): array
    {
        $values = [
            (string) config('mail.mailers.smtp.password'),
            (string) config('mail.mailers.smtp.username'),
            (string) config('services.fcm.private_key'),
            (string) config('broadcasting.connections.reverb.secret'),
        ];

        // Site settings (WP token, moderation key) live in the database —
        // unavailable before migrations run (e.g. during `migrate` itself),
        // so a lookup failure here must never break exception reporting.
        try {
            $values[] = (string) AppSetting::getValue('wordpress.apiToken');
            $values[] = (string) AppSetting::getValue('moderation.apiKey');
        } catch (Throwable) {
            // Reporting the original exception still matters more than this.
        }

        return array_values(array_unique(array_filter(
            $values,
            static fn (string $value) => $value !== '' && strtolower($value) !== 'null'
        )));
    }
}
