<?php

namespace App\Support;

// Staging/production must not silently inherit local defaults (SQLite,
// localhost URLs, debug, mock Reverb). Local and phpunit skip this.
class EnvironmentGuard
{
    /**
     * @return array<string, mixed>
     */
    public static function currentState(): array
    {
        $fcm = config('services.fcm', []);
        $reverb = config('broadcasting.connections.reverb', []);
        $reverbOptions = is_array($reverb['options'] ?? null) ? $reverb['options'] : [];
        $corsOrigins = config('cors.allowed_origins', []);

        return [
            'env' => (string) config('app.env', 'production'),
            'debug' => (bool) config('app.debug'),
            'url' => (string) config('app.url'),
            'key' => (string) config('app.key'),
            'db' => (string) config('database.default'),
            'queue' => (string) config('queue.default'),
            'broadcast' => (string) config('broadcasting.default'),
            'cors_origins' => is_array($corsOrigins) ? array_values($corsOrigins) : [],
            'reverb_key' => (string) ($reverb['key'] ?? ''),
            'reverb_secret' => (string) ($reverb['secret'] ?? ''),
            'reverb_host' => (string) ($reverbOptions['host'] ?? ''),
            'fcm_required' => (bool) config('services.fcm.required', false),
            'fcm_project_id' => (string) ($fcm['project_id'] ?? ''),
            'fcm_client_email' => (string) ($fcm['client_email'] ?? ''),
            'fcm_private_key' => (string) ($fcm['private_key'] ?? ''),
            'mailer' => (string) config('mail.default', 'log'),
            'mail_host' => (string) config('mail.mailers.smtp.host'),
            'mail_username' => (string) config('mail.mailers.smtp.username'),
            'mail_password' => (string) config('mail.mailers.smtp.password'),
            'mail_from_address' => (string) config('mail.from.address'),
        ];
    }

    public static function assertSafe(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        self::assertFor(self::currentState());
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function assertFor(array $state): void
    {
        $env = strtolower(trim((string) ($state['env'] ?? '')));
        if (in_array($env, ['local', 'testing', 'development'], true)) {
            return;
        }

        if (! in_array($env, ['staging', 'production'], true)) {
            throw new UnsafeEnvironmentException(
                "Unknown APP_ENV [$env]. Use local, staging, or production."
            );
        }

        $errors = [];

        if (! empty($state['debug'])) {
            $errors[] = 'APP_DEBUG must be false';
        }

        $url = trim((string) ($state['url'] ?? ''));
        if ($url === '' || self::isLoopbackUrl($url)) {
            $errors[] = 'APP_URL must be the public origin for this environment, not localhost';
        }

        if (trim((string) ($state['key'] ?? '')) === '') {
            $errors[] = 'APP_KEY is required';
        }

        if (($state['db'] ?? '') !== 'pgsql') {
            $errors[] = 'DB_CONNECTION must be pgsql (SQLite is local/test only)';
        }

        $queue = (string) ($state['queue'] ?? '');
        if ($queue === '' || $queue === 'sync') {
            $errors[] = 'QUEUE_CONNECTION must not be sync (use database or redis)';
        }

        $reverbKey = trim((string) ($state['reverb_key'] ?? ''));
        $reverbSecret = trim((string) ($state['reverb_secret'] ?? ''));
        $reverbHost = trim((string) ($state['reverb_host'] ?? ''));
        if ($reverbKey === '' || $reverbSecret === '' || $reverbHost === '') {
            $errors[] = 'REVERB_APP_KEY, REVERB_APP_SECRET, and REVERB_HOST are required';
        }
        if ($reverbHost !== '' && self::isLoopbackHost($reverbHost)) {
            $errors[] = 'REVERB_HOST must not be localhost in staging/production';
        }

        // P3-7 §18: never let a missing/blank CORS_ALLOWED_ORIGINS silently
        // fall through to Fruitcake\Cors's own wildcard-when-empty
        // behavior, and never allow a literal "*" (browsers already reject
        // "*" combined with credentials, but supports_credentials could
        // change later — this must hold regardless).
        $corsOrigins = array_values(array_filter(array_map(
            static fn ($origin) => trim((string) $origin),
            (array) ($state['cors_origins'] ?? [])
        ), static fn (string $origin) => $origin !== ''));
        if ($corsOrigins === []) {
            $errors[] = 'CORS_ALLOWED_ORIGINS must not be empty in staging/production (no silent wildcard fallback)';
        }
        foreach ($corsOrigins as $origin) {
            if ($origin === '*') {
                $errors[] = 'CORS_ALLOWED_ORIGINS must not contain a wildcard origin';
                break;
            }
        }
        foreach ($corsOrigins as $origin) {
            if (self::isLoopbackUrl($origin)) {
                $errors[] = 'CORS_ALLOWED_ORIGINS must not contain a localhost origin in staging/production';
                break;
            }
        }

        $projectId = trim((string) ($state['fcm_project_id'] ?? ''));
        $clientEmail = trim((string) ($state['fcm_client_email'] ?? ''));
        $privateKey = trim((string) ($state['fcm_private_key'] ?? ''));
        $fcmAny = $projectId !== '' || $clientEmail !== '' || $privateKey !== '';
        $fcmAll = $projectId !== '' && $clientEmail !== '' && $privateKey !== '';
        if ($fcmAny && ! $fcmAll) {
            $errors[] = 'FIREBASE_PROJECT_ID, FIREBASE_CLIENT_EMAIL, and FIREBASE_PRIVATE_KEY must be set together';
        }
        if (! empty($state['fcm_required']) && ! $fcmAll) {
            $errors[] = 'FCM_REQUIRED is set but Firebase server credentials are missing';
        }

        $mailer = strtolower(trim((string) ($state['mailer'] ?? 'log')));
        if ($env === 'production' && $mailer !== 'smtp') {
            $errors[] = 'MAIL_MAILER must be smtp in production (log is local-only)';
        }
        if ($mailer === 'smtp') {
            $host = trim((string) ($state['mail_host'] ?? ''));
            if ($host === '' || self::isLoopbackHost($host)) {
                $errors[] = 'MAIL_HOST must be the SMTP server, not localhost';
            }
            $from = trim((string) ($state['mail_from_address'] ?? ''));
            if ($from === '' || ! str_contains($from, '@') || strcasecmp($from, 'hello@example.com') === 0) {
                $errors[] = 'MAIL_FROM_ADDRESS must be a real sender, not the local placeholder';
            }
            if (trim((string) ($state['mail_username'] ?? '')) === ''
                || (string) ($state['mail_password'] ?? '') === '') {
                $errors[] = 'MAIL_USERNAME and MAIL_PASSWORD are required when MAIL_MAILER=smtp';
            }
        }

        if ($errors === []) {
            return;
        }

        throw new UnsafeEnvironmentException(
            'Unsafe '.$env.' config: '.implode('; ', $errors).'. Local defaults are not applied.'
        );
    }

    public static function isLoopbackUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && self::isLoopbackHost($host);
    }

    public static function isLoopbackHost(string $host): bool
    {
        $host = strtolower($host);

        return $host === 'localhost' || $host === '127.0.0.1' || $host === '::1' || $host === '0.0.0.0';
    }
}
