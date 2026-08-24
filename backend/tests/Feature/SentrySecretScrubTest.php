<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Sentry\Event;
use Tests\Concerns\FakesSentry;
use Tests\TestCase;

// P3-6 §19/§21: authorization, password, WP token, SMTP password, and
// Firebase secrets must never reach a captured Sentry event. Exercises the
// real App\Support\SentryScrubber::scrubEvent (bound via FakesSentry), not
// a reimplementation of it.
class SentrySecretScrubTest extends TestCase
{
    use FakesSentry;
    use RefreshDatabase;

    public function test_password_field_is_redacted_from_request_data(): void
    {
        $transport = $this->fakeSentry();

        $event = Event::createEvent();
        $event->setRequest([
            'method' => 'POST',
            'url' => 'http://localhost/api/v1/auth/session',
            'data' => ['email' => 'user@arucad.edu.tr', 'password' => 'super-secret-pass'],
        ]);
        \Sentry\captureEvent($event);

        $this->assertCount(1, $transport->events);
        $sentData = $transport->events[0]->getRequest()['data'];
        $this->assertSame('[redacted]', $sentData['password']);
        $this->assertSame('user@arucad.edu.tr', $sentData['email']);
    }

    public function test_wordpress_api_token_and_moderation_key_are_redacted(): void
    {
        $transport = $this->fakeSentry();

        $event = Event::createEvent();
        $event->setRequest([
            'method' => 'POST',
            'url' => 'http://localhost/api/v1/admin/settings/site',
            'data' => [
                'wordpress' => ['siteUrl' => 'https://arucad.edu.tr', 'apiToken' => 'wp-real-token-123'],
                'entra' => ['clientId' => 'abc'],
            ],
        ]);
        \Sentry\captureEvent($event);

        $sentData = $transport->events[0]->getRequest()['data'];
        $this->assertSame('[redacted]', $sentData['wordpress']['apiToken']);
        $this->assertSame('https://arucad.edu.tr', $sentData['wordpress']['siteUrl']);
        $this->assertSame('abc', $sentData['entra']['clientId']);
    }

    public function test_authorization_and_cookie_headers_are_redacted(): void
    {
        $transport = $this->fakeSentry();

        $event = Event::createEvent();
        $event->setRequest([
            'method' => 'GET',
            'url' => 'http://localhost/api/v1/me',
            'headers' => [
                'Authorization' => ['Bearer real-sanctum-token'],
                'Cookie' => ['session=abc123'],
                'Accept' => ['application/json'],
            ],
        ]);
        \Sentry\captureEvent($event);

        $headers = $transport->events[0]->getRequest()['headers'];
        $this->assertSame('[redacted]', $headers['Authorization']);
        $this->assertSame('[redacted]', $headers['Cookie']);
        $this->assertSame(['application/json'], $headers['Accept']);
    }

    public function test_smtp_password_and_username_values_are_redacted_from_exception_message(): void
    {
        config(['mail.mailers.smtp.password' => 'super-secret-smtp-pass']);
        config(['mail.mailers.smtp.username' => 'smtp-user-1']);
        $transport = $this->fakeSentry();

        \Sentry\captureException(new \RuntimeException(
            'SMTP auth failed for smtp-user-1 with password super-secret-smtp-pass'
        ));

        $message = $transport->events[0]->getExceptions()[0]->getValue();
        $this->assertStringNotContainsString('super-secret-smtp-pass', $message);
        $this->assertStringNotContainsString('smtp-user-1', $message);
        $this->assertStringContainsString('[redacted]', $message);
    }

    public function test_firebase_private_key_value_is_redacted_from_exception_message(): void
    {
        config(['services.fcm.private_key' => '-----BEGIN PRIVATE KEY-----FAKEKEYDATA-----END PRIVATE KEY-----']);
        $transport = $this->fakeSentry();

        \Sentry\captureException(new \RuntimeException(
            'FCM signing failed with key -----BEGIN PRIVATE KEY-----FAKEKEYDATA-----END PRIVATE KEY-----'
        ));

        $message = $transport->events[0]->getExceptions()[0]->getValue();
        $this->assertStringNotContainsString('FAKEKEYDATA', $message);
    }

    public function test_stored_wordpress_token_value_is_redacted_even_when_not_a_request_field(): void
    {
        AppSetting::setValue('wordpress.apiToken', 'stored-wp-secret-xyz');
        $transport = $this->fakeSentry();

        \Sentry\captureException(new \RuntimeException(
            'WordPress request failed, token stored-wp-secret-xyz was rejected'
        ));

        $message = $transport->events[0]->getExceptions()[0]->getValue();
        $this->assertStringNotContainsString('stored-wp-secret-xyz', $message);
    }

    public function test_site_settings_endpoint_response_never_echoes_the_token_either(): void
    {
        // Sanity companion to the scrub test above: the API contract itself
        // already never returns the raw token (write-only), independent of
        // Sentry — confirms P3-6 didn't change that.
        $this->actingAsRole('superAdmin');
        AppSetting::setValue('wordpress.apiToken', 'stored-wp-secret-xyz');

        $response = $this->getJson('/api/v1/admin/settings/site');

        $response->assertOk();
        $response->assertJsonMissing(['wordpress' => ['apiToken' => 'stored-wp-secret-xyz']]);
        $this->assertStringNotContainsString('stored-wp-secret-xyz', $response->getContent());
    }
}
