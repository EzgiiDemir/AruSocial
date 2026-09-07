<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EntraAuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unconfigured_entra_is_honest_501(): void
    {
        config([
            'services.entra.tenant_id' => '',
            'services.entra.client_id' => '',
        ]);

        $this->getJson('/api/v1/auth/entra/config')
            ->assertOk()
            ->assertJsonPath('data.configured', false);

        $this->postJson('/api/v1/auth/entra', ['idToken' => 'not-a-jwt'])
            ->assertStatus(501)
            ->assertJsonPath('error.code', 'ENTRA_NOT_CONFIGURED');
    }

    public function test_a_valid_id_token_issues_a_sanctum_session(): void
    {
        [$token] = $this->mintSignedIdToken(
            tenant: 'tenant-1',
            client: 'client-1',
            email: 'entra.user@arucad.edu.tr',
        );

        $this->postJson('/api/v1/auth/entra', ['idToken' => $token])
            ->assertOk()
            ->assertJsonPath('data.user.name', 'Entra User');
        $this->assertNotEmpty(
            $this->postJson('/api/v1/auth/entra', ['idToken' => $token])->json('data.token')
        );
        $this->assertDatabaseHas('users', ['email' => 'entra.user@arucad.edu.tr']);
    }

    public function test_wrong_email_domain_is_rejected(): void
    {
        [$token] = $this->mintSignedIdToken(
            tenant: 'tenant-1',
            client: 'client-1',
            email: 'outsider@gmail.com',
        );

        $this->postJson('/api/v1/auth/entra', ['idToken' => $token])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'ENTRA_TOKEN_INVALID');
    }

    /**
     * @return array{0: string}
     */
    private function mintSignedIdToken(string $tenant, string $client, string $email): array
    {
        config([
            'services.entra.tenant_id' => $tenant,
            'services.entra.client_id' => $client,
            'services.entra.redirect_uri' => 'com.example.arucad_campus_prototype://oauthredirect',
            'auth.allowed_email_domain' => '@arucad.edu.tr',
            'auth.allowed_email_domains' => ['@arucad.edu.tr'],
        ]);

        $cnf = str_replace('\\', '/', realpath(dirname(__DIR__).'/Fixtures/openssl.cnf') ?: '');
        $this->assertNotSame('', $cnf, 'tests/Fixtures/openssl.cnf is missing');
        putenv('OPENSSL_CONF='.$cnf);
        $opensslConfig = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'config' => $cnf,
        ];
        $key = openssl_pkey_new($opensslConfig);
        $this->assertNotFalse($key, 'openssl_pkey_new failed: '.openssl_error_string());
        $details = openssl_pkey_get_details($key);
        $n = rtrim(strtr(base64_encode($details['rsa']['n']), '+/', '-_'), '=');
        $e = rtrim(strtr(base64_encode($details['rsa']['e']), '+/', '-_'), '=');

        Http::fake([
            'login.microsoftonline.com/*' => Http::response([
                'keys' => [[
                    'kty' => 'RSA',
                    'kid' => 'test-kid',
                    'n' => $n,
                    'e' => $e,
                    'use' => 'sig',
                    'alg' => 'RS256',
                ]],
            ], 200),
        ]);

        $header = $this->b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'test-kid']));
        $payload = $this->b64url(json_encode([
            'aud' => $client,
            'iss' => "https://login.microsoftonline.com/{$tenant}/v2.0",
            'tid' => $tenant,
            'email' => $email,
            'name' => 'Entra User',
            'exp' => time() + 3600,
            'nbf' => time() - 30,
            'oid' => 'oid-test',
            'sub' => 'sub-test',
        ]));
        openssl_sign($header.'.'.$payload, $signature, $key, OPENSSL_ALGO_SHA256);
        $token = $header.'.'.$payload.'.'.$this->b64url($signature);

        return [$token];
    }

    private function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
