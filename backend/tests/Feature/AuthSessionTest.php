<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthSessionTest extends TestCase
{
    use RefreshDatabase;

    private function existingUser(string $password = 'demo-password'): User
    {
        return User::create([
            'name' => 'Ege Aydın',
            'email' => 'ege.aydin@arucad.edu.tr',
            'password' => bcrypt($password),
        ]);
    }

    public function test_valid_credentials_return_a_token_and_the_user(): void
    {
        $user = $this->existingUser();

        $response = $this->postJson('/api/v1/auth/session', [
            'email' => 'ege.aydin@arucad.edu.tr',
            'password' => 'demo-password',
        ]);

        $response->assertOk();
        $this->assertNotEmpty($response->json('data.token'));
        $this->assertEquals((string) $user->id, $response->json('data.user.id'));
        $this->assertNull($response->json('error'));
        $this->assertStringStartsWith('req-', $response->json('meta.request_id'));
        $this->assertDatabaseHas('personal_access_tokens', ['tokenable_id' => $user->id]);
    }

    // The whole point of this milestone: the password is really checked
    // now, where before any non-empty string signed anyone in.
    public function test_a_wrong_password_is_rejected(): void
    {
        $this->existingUser();

        $response = $this->postJson('/api/v1/auth/session', [
            'email' => 'ege.aydin@arucad.edu.tr',
            'password' => 'not-the-password',
        ]);

        $response->assertStatus(401);
        $this->assertEquals('INVALID_CREDENTIALS', $response->json('error.code'));
        $this->assertNull($response->json('data'));
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_an_email_outside_the_allowed_domain_is_rejected(): void
    {
        // Pin allowlist so local AUTH_ALLOWED_EMAIL_DOMAINS=@gmail.com smoke
        // config cannot make this assertion flake.
        config([
            'auth.allowed_email_domain' => '@arucad.edu.tr',
            'auth.allowed_email_domains' => ['@arucad.edu.tr'],
        ]);

        $response = $this->postJson('/api/v1/auth/session', [
            'email' => 'someone@example.com',
            'password' => 'whatever',
        ]);

        $response->assertStatus(403);
        $this->assertEquals('DOMAIN_NOT_ALLOWED', $response->json('error.code'));
        $this->assertDatabaseMissing('users', ['email' => 'someone@example.com']);
    }

    public function test_missing_credentials_are_rejected(): void
    {
        $this->postJson('/api/v1/auth/session', ['email' => 'x@arucad.edu.tr', 'password' => ''])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION');
    }

    // No student directory exists to pre-provision accounts from, so a
    // first sign-in registers one — and the password it registers with is
    // what every later sign-in has to match.
    public function test_a_first_time_account_is_registered_with_the_password_it_signs_in_with(): void
    {
        $this->postJson('/api/v1/auth/session', [
            'email' => 'yeni.ogrenci@arucad.edu.tr',
            'password' => 'ilk-sifre',
        ])->assertOk();

        $created = User::where('email', 'yeni.ogrenci@arucad.edu.tr')->firstOrFail();
        $this->assertTrue(Hash::check('ilk-sifre', $created->password));
        $this->assertEquals('Yeni Ogrenci', $created->name);

        $this->postJson('/api/v1/auth/session', [
            'email' => 'yeni.ogrenci@arucad.edu.tr',
            'password' => 'baska-sifre',
        ])->assertStatus(401);
    }

    public function test_a_first_time_account_can_use_a_short_existing_password(): void
    {
        $this->postJson('/api/v1/auth/session', [
            'email' => 'kisa.sifre@arucad.edu.tr',
            'password' => 'short',
        ])->assertOk();

        $created = User::where('email', 'kisa.sifre@arucad.edu.tr')->firstOrFail();
        $this->assertTrue(Hash::check('short', $created->password));
    }

    public function test_a_banned_account_cannot_open_a_session_at_all(): void
    {
        $this->existingUser()->update(['banned_at' => now()]);

        $response = $this->postJson('/api/v1/auth/session', [
            'email' => 'ege.aydin@arucad.edu.tr',
            'password' => 'demo-password',
        ]);

        $response->assertStatus(403);
        $this->assertEquals('ACCOUNT_BANNED', $response->json('error.code'));
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_the_token_actually_authenticates_me_and_survives_until_logout(): void
    {
        $user = $this->existingUser();
        $token = $this->postJson('/api/v1/auth/session', [
            'email' => 'ege.aydin@arucad.edu.tr',
            'password' => 'demo-password',
        ])->json('data.token');

        $this->withToken($token)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.id', (string) $user->id);

        $this->withToken($token)->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('data.signedOut', true);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_a_revoked_token_no_longer_authenticates(): void
    {
        $this->existingUser();
        $token = $this->postJson('/api/v1/auth/session', [
            'email' => 'ege.aydin@arucad.edu.tr',
            'password' => 'demo-password',
        ])->json('data.token');

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();

        $this->withToken($token)->getJson('/api/v1/me')
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'AUTH_REQUIRED');
    }

    // Signing out on one device must not sign the account out everywhere.
    public function test_logout_revokes_only_the_token_it_was_called_with(): void
    {
        $this->existingUser();
        $credentials = ['email' => 'ege.aydin@arucad.edu.tr', 'password' => 'demo-password'];
        $phone = $this->postJson('/api/v1/auth/session', $credentials)->json('data.token');
        $laptop = $this->postJson('/api/v1/auth/session', $credentials)->json('data.token');

        $this->withToken($phone)->postJson('/api/v1/auth/logout')->assertOk();

        $this->withToken($phone)->getJson('/api/v1/me')->assertStatus(401);
        $this->withToken($laptop)->getJson('/api/v1/me')->assertOk();
    }
}
