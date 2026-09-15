<?php

namespace Tests\Feature;

use App\Models\PolicyConsent;
use App\Models\User;
use App\Services\Legal\PolicyDocuments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Consent has to be a record about a person, not a flag on a phone.
 *
 * The previous implementation stored a boolean in SharedPreferences. That
 * answers "did this device dismiss a screen", which is not the question
 * anyone will ask. The questions that matter are: did this student accept,
 * when, which version, and in which language — and each has to survive a
 * reinstall and a new phone.
 */
class PolicyConsentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        PolicyDocuments::forgetCachedVersion();
    }

    protected function tearDown(): void
    {
        PolicyDocuments::forgetCachedVersion();
        parent::tearDown();
    }

    public function test_a_new_account_is_asked_to_accept(): void
    {
        $this->actingAsUser();

        $this->getJson('/api/v1/me/policy-consent')
            ->assertOk()
            ->assertJsonPath('data.required', true)
            ->assertJsonPath('data.document', 'privacy')
            ->assertJsonPath('data.acceptedAt', null);
    }

    public function test_accepting_records_who_when_which_version_and_which_language(): void
    {
        $me = $this->actingAsUser();

        $this->postJson('/api/v1/me/policy-consent', ['locale' => 'ru'])
            ->assertOk()
            ->assertJsonPath('data.required', false)
            ->assertJsonPath('data.acceptedLocale', 'ru');

        $consent = PolicyConsent::where('user_id', $me->id)->firstOrFail();

        $this->assertSame('privacy', $consent->document);
        $this->assertSame(PolicyDocuments::currentVersion(), $consent->version);
        $this->assertSame('ru', $consent->locale);
        $this->assertNotNull($consent->accepted_at);
    }

    public function test_once_accepted_it_is_not_asked_again(): void
    {
        $this->actingAsUser();
        $this->postJson('/api/v1/me/policy-consent', ['locale' => 'tr'])->assertOk();

        $this->getJson('/api/v1/me/policy-consent')
            ->assertOk()
            ->assertJsonPath('data.required', false);
    }

    /**
     * The moment the person actually agreed is the one worth keeping, so a
     * repeat acceptance must not overwrite it with a later timestamp.
     */
    public function test_accepting_twice_keeps_the_first_record(): void
    {
        $me = $this->actingAsUser();

        $this->postJson('/api/v1/me/policy-consent', ['locale' => 'tr'])->assertOk();
        $first = PolicyConsent::where('user_id', $me->id)->firstOrFail();

        $this->travel(2)->days();
        $this->postJson('/api/v1/me/policy-consent', ['locale' => 'en'])->assertOk();

        $this->assertSame(1, PolicyConsent::where('user_id', $me->id)->count());

        $again = PolicyConsent::where('user_id', $me->id)->firstOrFail();
        $this->assertEquals($first->accepted_at, $again->accepted_at);
        $this->assertSame('tr', $again->locale, 'The language first read should stand.');
    }

    /**
     * The point of versioning. An edit to any of the three translations has
     * to produce a new version and a fresh prompt — a student who accepted
     * the old wording has not accepted the new one.
     */
    public function test_editing_the_policy_asks_everyone_again(): void
    {
        $this->actingAsUser();
        $this->postJson('/api/v1/me/policy-consent', ['locale' => 'tr'])->assertOk();
        $this->getJson('/api/v1/me/policy-consent')->assertJsonPath('data.required', false);

        $path = PolicyDocuments::path('privacy', 'tr');
        $original = File::get($path);

        try {
            File::put($path, $original."\n\nEk madde.\n");
            PolicyDocuments::forgetCachedVersion();

            $this->getJson('/api/v1/me/policy-consent')
                ->assertOk()
                ->assertJsonPath('data.required', true);
        } finally {
            File::put($path, $original);
            PolicyDocuments::forgetCachedVersion();
        }
    }

    /**
     * A Russian edit alone must still re-prompt: a Russian-speaking student
     * agreed to the Russian wording, not the Turkish one.
     */
    public function test_editing_only_a_translation_still_changes_the_version(): void
    {
        $before = PolicyDocuments::currentVersion();

        $path = PolicyDocuments::path('privacy', 'ru');
        $original = File::get($path);

        try {
            File::put($path, $original."\n\nДополнительный пункт.\n");
            PolicyDocuments::forgetCachedVersion();

            $this->assertNotSame($before, PolicyDocuments::currentVersion());
        } finally {
            File::put($path, $original);
            PolicyDocuments::forgetCachedVersion();
        }
    }

    /**
     * The client says "I accepted what you showed me" and nothing else. A
     * client that could name the version could record consent to a policy
     * that is no longer in force.
     */
    public function test_a_client_cannot_choose_which_version_it_consents_to(): void
    {
        $me = $this->actingAsUser();

        $this->postJson('/api/v1/me/policy-consent', [
            'locale' => 'tr',
            'version' => 'privacy-0000deadbeef',
        ])->assertOk();

        $this->assertSame(
            PolicyDocuments::currentVersion(),
            PolicyConsent::where('user_id', $me->id)->firstOrFail()->version,
        );
    }

    public function test_an_unknown_language_is_refused(): void
    {
        $this->actingAsUser();

        $this->postJson('/api/v1/me/policy-consent', ['locale' => 'de'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'VALIDATION');
    }

    public function test_consent_endpoints_require_a_signed_in_account(): void
    {
        $this->getJson('/api/v1/me/policy-consent')->assertStatus(401);
        $this->postJson('/api/v1/me/policy-consent', ['locale' => 'tr'])->assertStatus(401);
    }

    /**
     * Erasing an account has to take its consent records with it, or the
     * table keeps rows naming a person who has been deleted.
     */
    public function test_deleting_an_account_removes_its_consent_records(): void
    {
        $me = $this->actingAsUser();
        $this->postJson('/api/v1/me/policy-consent', ['locale' => 'tr'])->assertOk();

        $this->assertSame(1, PolicyConsent::count());

        User::find($me->id)->delete();

        $this->assertSame(0, PolicyConsent::count());
    }

    public function test_accepting_is_written_to_the_audit_trail(): void
    {
        $me = $this->actingAsUser();

        $this->postJson('/api/v1/me/policy-consent', ['locale' => 'tr'])->assertOk();

        $this->assertDatabaseHas('admin_audit_log', [
            'actor_name' => $me->email,
            'action' => 'accept',
            'target_type' => 'policy_consent',
        ]);
    }

    /**
     * The moderation section has to exist in the published policy in all
     * three languages. The login screen summarises it in three lines; this
     * is the full statement those three lines point at.
     */
    public function test_the_moderation_section_exists_in_every_language(): void
    {
        $headings = [
            'tr' => '## 3. İçerik Moderasyonu',
            'en' => '## 3. Content Moderation',
            'ru' => '## 3. Модерация контента',
        ];

        foreach ($headings as $locale => $heading) {
            $this->assertStringContainsString(
                $heading,
                File::get(PolicyDocuments::path('privacy', $locale)),
                "The {$locale} privacy policy has no content moderation section."
            );
        }
    }

    /**
     * The checkbox names two documents, so both have to exist in all three
     * languages — a consent to something unreadable is not a consent.
     */
    public function test_both_consentable_documents_exist_in_every_language(): void
    {
        foreach (PolicyDocuments::CONSENTABLE as $document) {
            foreach (PolicyDocuments::LOCALES as $locale) {
                $path = PolicyDocuments::path($document, $locale);

                $this->assertFileExists($path);

                if ($locale !== 'tr') {
                    $this->assertStringContainsString(
                        ".{$locale}.md",
                        $path,
                        "The {$locale} {$document} falls back to the Turkish original."
                    );
                }

                $this->assertGreaterThan(
                    800,
                    strlen(File::get($path)),
                    "The {$locale} {$document} looks truncated."
                );
            }
        }
    }

    /**
     * The version covers both documents, so editing the guidelines alone
     * still has to re-open the consent gate. Nothing else would notice:
     * the privacy policy is untouched and the screen looks identical.
     */
    public function test_editing_the_community_guidelines_asks_everyone_again(): void
    {
        $this->actingAsUser();
        $this->postJson('/api/v1/me/policy-consent', ['locale' => 'tr'])->assertOk();
        $this->getJson('/api/v1/me/policy-consent')->assertJsonPath('data.required', false);

        $path = PolicyDocuments::path(PolicyDocuments::GUIDELINES, 'tr');
        $original = File::get($path);

        try {
            File::put($path, $original."\n\n## 11. Ek madde\n\nYeni kural.\n");
            PolicyDocuments::forgetCachedVersion();

            $this->getJson('/api/v1/me/policy-consent')
                ->assertOk()
                ->assertJsonPath('data.required', true);
        } finally {
            File::put($path, $original);
            PolicyDocuments::forgetCachedVersion();
        }
    }
}
