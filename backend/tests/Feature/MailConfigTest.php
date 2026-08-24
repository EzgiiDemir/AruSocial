<?php

namespace Tests\Feature;

use App\Support\EnvironmentGuard;
use App\Support\UnsafeEnvironmentException;
use Tests\TestCase;

class MailConfigTest extends TestCase
{
    public function test_local_and_testing_use_log_or_array_without_smtp_credentials(): void
    {
        $this->assertSame('testing', config('app.env'));
        $this->assertContains(config('mail.default'), ['array', 'log']);
        EnvironmentGuard::assertFor([
            'env' => 'local',
            'debug' => true,
            'db' => 'sqlite',
            'mailer' => 'log',
            'mail_host' => '127.0.0.1',
            'mail_username' => '',
            'mail_password' => '',
            'mail_from_address' => 'hello@example.com',
        ]);
        $this->addToAssertionCount(1);
    }

    public function test_staging_may_keep_log_mailer(): void
    {
        EnvironmentGuard::assertFor($this->stagingLogState());
        $this->addToAssertionCount(1);
    }

    public function test_staging_smtp_requires_host_from_and_credentials(): void
    {
        $this->expectException(UnsafeEnvironmentException::class);
        EnvironmentGuard::assertFor([
            ...$this->stagingLogState(),
            'mailer' => 'smtp',
            'mail_host' => '127.0.0.1',
            'mail_username' => '',
            'mail_password' => '',
            'mail_from_address' => 'hello@example.com',
        ]);
    }

    public function test_production_rejects_log_mailer(): void
    {
        $this->expectException(UnsafeEnvironmentException::class);
        EnvironmentGuard::assertFor([
            ...$this->productionSmtpState(),
            'mailer' => 'log',
        ]);
    }

    public function test_production_smtp_is_accepted_when_complete(): void
    {
        EnvironmentGuard::assertFor($this->productionSmtpState());
        $this->addToAssertionCount(1);
    }

    public function test_smtp_does_not_accept_the_local_from_placeholder(): void
    {
        $this->expectException(UnsafeEnvironmentException::class);
        EnvironmentGuard::assertFor([
            ...$this->productionSmtpState(),
            'mail_from_address' => 'hello@example.com',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function stagingLogState(): array
    {
        return [
            'env' => 'staging',
            'debug' => false,
            'url' => 'https://staging-api.example.com',
            'key' => 'base64:test-key',
            'db' => 'pgsql',
            'queue' => 'database',
            'cors_origins' => ['https://staging-app.example.com'],
            'reverb_key' => 'k',
            'reverb_secret' => 's',
            'reverb_host' => 'staging.reverb.example.com',
            'mailer' => 'log',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function productionSmtpState(): array
    {
        return [
            'env' => 'production',
            'debug' => false,
            'url' => 'https://api.example.com',
            'key' => 'base64:test-key',
            'db' => 'pgsql',
            'queue' => 'database',
            'cors_origins' => ['https://app.example.com'],
            'reverb_key' => 'k',
            'reverb_secret' => 's',
            'reverb_host' => 'ws.example.com',
            'mailer' => 'smtp',
            'mail_host' => 'smtp.example.com',
            'mail_username' => 'campus',
            'mail_password' => 'not-a-real-secret',
            'mail_from_address' => 'campus@example.com',
        ];
    }
}
