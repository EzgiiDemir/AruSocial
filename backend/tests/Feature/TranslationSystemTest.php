<?php

namespace Tests\Feature;

use App\Models\Translation;
use App\Models\TranslationKey;
use App\Models\TranslationRevision;
use App\Services\Translations\TranslationCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Translations are data, not code.
 *
 * The app's 594 strings were compiled into `app_strings.dart`, so fixing one
 * word meant a build, a review and a store submission. These tests cover the
 * path that replaces that: edit a draft, publish it, and every phone picks it
 * up on its next check.
 *
 * The ones that matter most are the negatives — a draft must never reach a
 * phone, and a missing translation must show Turkish rather than a raw key.
 * A student shown `sp_private` has been failed worse than one shown the
 * wrong language.
 */
class TranslationSystemTest extends TestCase
{
    use RefreshDatabase;

    private function key(string $key, array $values = [], bool $publish = true): TranslationKey
    {
        $model = TranslationKey::create(['key' => $key, 'area' => 'test']);

        foreach ($values as $locale => $value) {
            Translation::create([
                'translation_key_id' => $model->id,
                'locale' => $locale,
                'draft' => $value,
                'published' => $publish ? $value : null,
                'published_at' => $publish ? now() : null,
            ]);
        }

        TranslationCatalogue::forget();

        return $model->fresh('translations');
    }

    // ---- delivery ----------------------------------------------------

    public function test_the_app_can_fetch_a_language_without_signing_in(): void
    {
        $this->key('greeting', ['tr' => 'Merhaba', 'en' => 'Hello', 'ru' => 'Привет']);

        $body = $this->getJson('/api/v1/translations?lang=en')
            ->assertOk()
            ->json('data');

        $this->assertSame('en', $body['locale']);
        $this->assertSame('Hello', $body['strings']['greeting']);
        $this->assertNotEmpty($body['version']);
    }

    /**
     * The sign-in screen needs its own words before anybody has a token, so
     * this endpoint is deliberately public. Asserted so that a later sweep
     * adding `auth:sanctum` everywhere cannot silently lock the app out of
     * its own labels.
     */
    public function test_the_endpoint_is_public_by_design(): void
    {
        $this->key('login_title', ['tr' => 'Giriş']);

        $this->getJson('/api/v1/translations?lang=tr')->assertOk();
    }

    public function test_an_unknown_language_falls_back_to_turkish(): void
    {
        $this->key('greeting', ['tr' => 'Merhaba', 'en' => 'Hello']);

        $body = $this->getJson('/api/v1/translations?lang=de')->assertOk()->json('data');

        $this->assertSame('tr', $body['locale']);
        $this->assertSame('Merhaba', $body['strings']['greeting']);
    }

    /**
     * A key translated in Turkish but not yet in Russian must render the
     * Turkish text, never the key name.
     */
    public function test_a_missing_translation_falls_back_rather_than_showing_the_key(): void
    {
        $this->key('only_turkish', ['tr' => 'Sadece Türkçe']);

        $strings = $this->getJson('/api/v1/translations?lang=ru')->json('data.strings');

        $this->assertSame('Sadece Türkçe', $strings['only_turkish']);
        $this->assertArrayNotHasKey('only_turkish_missing', $strings);
    }

    public function test_an_unchanged_catalogue_answers_304(): void
    {
        $this->key('greeting', ['tr' => 'Merhaba']);

        $etag = $this->getJson('/api/v1/translations?lang=tr')
            ->assertOk()
            ->headers->get('ETag');

        $this->assertNotEmpty($etag);

        $this->getJson('/api/v1/translations?lang=tr', ['If-None-Match' => $etag])
            ->assertStatus(304);
    }

    public function test_publishing_changes_the_version_so_phones_refetch(): void
    {
        $key = $this->key('greeting', ['tr' => 'Merhaba']);

        $before = $this->getJson('/api/v1/translations?lang=tr')->headers->get('ETag');

        $translation = $key->translations->first();
        $translation->update(['draft' => 'Selam']);
        $translation->publish('tester');

        $after = $this->getJson('/api/v1/translations?lang=tr')->headers->get('ETag');

        $this->assertNotSame($before, $after, 'The ETag did not change after publishing.');
        $this->assertSame('Selam',
            $this->getJson('/api/v1/translations?lang=tr')->json('data.strings.greeting'));
    }

    // ---- preview and publish -----------------------------------------

    /** The whole point of a draft: students keep seeing the old wording. */
    public function test_a_draft_is_never_served_to_the_app(): void
    {
        $key = $this->key('greeting', ['tr' => 'Merhaba']);

        $key->translations->first()->update(['draft' => 'YAYINLANMAMIŞ']);
        TranslationCatalogue::forget();

        $strings = $this->getJson('/api/v1/translations?lang=tr')->json('data.strings');

        $this->assertSame('Merhaba', $strings['greeting']);
        $this->assertNotContains('YAYINLANMAMIŞ', $strings);
    }

    public function test_publishing_an_empty_draft_does_nothing(): void
    {
        $key = $this->key('greeting', ['tr' => 'Merhaba']);
        $translation = $key->translations->first();

        $translation->update(['draft' => null]);
        $translation->publish('tester');

        $this->assertSame('Merhaba', $translation->fresh()->published);
    }

    // ---- history and rollback ----------------------------------------

    public function test_every_publish_is_recorded_with_who_did_it(): void
    {
        $key = $this->key('greeting', ['tr' => 'Merhaba']);
        $translation = $key->translations->first();

        $translation->update(['draft' => 'Selam']);
        $translation->publish('editor@arucad.edu.tr');

        $revision = TranslationRevision::where('translation_key_id', $key->id)->latest('id')->first();

        $this->assertNotNull($revision);
        $this->assertSame('Selam', $revision->value);
        $this->assertSame('editor@arucad.edu.tr', $revision->actor);
    }

    public function test_a_previous_version_can_be_restored(): void
    {
        // Seeded as an unpublished draft, then published through the real
        // path — otherwise the first value has no revision behind it and
        // there is nothing to roll back *to*.
        $key = $this->key('greeting', ['tr' => 'Merhaba'], publish: false);
        $translation = $key->translations->first();
        $translation->publish('editor');

        $original = TranslationRevision::where('translation_key_id', $key->id)
            ->orderBy('id')
            ->first();
        $this->assertSame('Merhaba', $original->value);

        $translation->update(['draft' => 'Selam']);
        $translation->publish('editor');
        $this->assertSame('Selam', $translation->fresh()->published);

        // A rollback republishes rather than rewriting history.
        $translation->rollbackTo($original, 'editor');

        $this->assertSame('Merhaba', $translation->fresh()->published);
        $this->assertSame(3, TranslationRevision::where('translation_key_id', $key->id)->count(),
            'Rollback should add a revision, not erase one.');
    }

    // ---- missing detection -------------------------------------------

    public function test_missing_translations_are_detected_per_language(): void
    {
        $this->key('complete', ['tr' => 'A', 'en' => 'B', 'ru' => 'C']);
        $this->key('turkish_only', ['tr' => 'A']);

        $missing = TranslationCatalogue::missing();

        $this->assertSame([], $missing['tr']);
        $this->assertContains('turkish_only', $missing['en']);
        $this->assertContains('turkish_only', $missing['ru']);
        $this->assertNotContains('complete', $missing['en']);
    }

    public function test_a_key_reports_which_languages_it_is_missing(): void
    {
        $key = $this->key('turkish_only', ['tr' => 'A']);

        $this->assertSame(['en', 'ru'], $key->missingLocales());
    }

    public function test_a_key_reports_unpublished_edits(): void
    {
        $key = $this->key('greeting', ['tr' => 'Merhaba']);
        $key->translations->first()->update(['draft' => 'Selam']);

        $this->assertSame(['tr'], $key->fresh('translations')->unpublishedLocales());
    }

    // ---- cache -------------------------------------------------------

    public function test_the_cache_is_dropped_when_something_is_published(): void
    {
        $key = $this->key('greeting', ['tr' => 'Merhaba']);

        // Warm it.
        $this->assertSame('Merhaba', TranslationCatalogue::for('tr')['greeting']);

        $translation = $key->translations->first();
        $translation->update(['draft' => 'Selam']);
        $translation->publish('tester');

        $this->assertSame('Selam', TranslationCatalogue::for('tr')['greeting'],
            'The catalogue served a stale string after publishing.');
    }
}
