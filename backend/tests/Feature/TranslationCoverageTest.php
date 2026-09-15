<?php

namespace Tests\Feature;

use App\Models\TranslationKey;
use App\Services\Legal\PolicyDocuments;
use App\Services\Translations\AppStringsParser;
use App\Services\Translations\TranslationCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Nobody should ever see a raw key, or the wrong language.
 *
 * The app ships a complete reviewed copy of every string, and the panel
 * manages the same keys so wording can be fixed without a store release.
 * That arrangement has one failure mode and it is silent: a key added to
 * the app but never imported. The app renders it fine from its bundle, the
 * panel has no idea it exists, and the first sign of trouble is someone
 * asking why a button cannot be translated.
 *
 * These tests run against the real files and the real catalogue rather
 * than fixtures, because the thing being checked *is* whether the real
 * ones agree.
 */
class TranslationCoverageTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array<string, string>> */
    private function appTable(): array
    {
        $table = AppStringsParser::parseFile();

        $this->assertNotEmpty(
            $table,
            'Parsed no keys from app_strings.dart — the Dart table format has '
            .'probably changed, and every other test in this file is now vacuous.'
        );

        return $table;
    }

    private function seedFromApp(): void
    {
        $this->artisan('translations:import', ['--publish' => true])
            ->assertSuccessful();
    }

    // ---- the app's own bundle ------------------------------------------

    /**
     * A key with only Turkish falls back silently, so a half-added string
     * looks complete in testing and ships untranslated to exactly the
     * students who needed the translation.
     */
    public function test_every_app_string_exists_in_all_three_languages(): void
    {
        $missing = [];

        foreach ($this->appTable() as $key => $byLocale) {
            foreach (PolicyDocuments::LOCALES as $locale) {
                if (blank($byLocale[$locale] ?? null)) {
                    $missing[] = "{$key} -> {$locale}";
                }
            }
        }

        $this->assertSame([], $missing, sprintf(
            "%d app string(s) are missing a language:\n%s",
            count($missing),
            implode("\n", array_slice($missing, 0, 30)),
        ));
    }

    /**
     * A translation that is character-for-character the Turkish original in
     * all three languages is usually an untranslated placeholder. Proper
     * nouns and shared words legitimately match, so this reports rather
     * than fails — it is a list to work through, not a gate.
     */
    public function test_report_strings_identical_across_all_languages(): void
    {
        $identical = [];

        foreach ($this->appTable() as $key => $byLocale) {
            $values = array_unique(array_map('trim', array_values($byLocale)));
            if (count($byLocale) === 3 && count($values) === 1 && mb_strlen($values[0]) > 12) {
                $identical[] = $key.': '.$values[0];
            }
        }

        // Deliberately not an assertion on the count. Recorded so the
        // number is visible and someone can decide.
        if ($identical !== []) {
            fwrite(STDERR, sprintf(
                "\n%d string(s) are identical in all three languages:\n%s\n",
                count($identical),
                implode("\n", array_slice($identical, 0, 20)),
            ));
        }

        $this->assertTrue(true);
    }

    // ---- the app and the panel must agree ------------------------------

    /**
     * The one that matters. A key the panel does not know about cannot be
     * corrected without an App Store release, which is the whole point of
     * having a panel-managed catalogue.
     */
    public function test_every_app_string_is_manageable_from_the_panel(): void
    {
        $this->seedFromApp();

        $known = TranslationKey::query()->pluck('key')->all();
        $unmanaged = array_values(array_diff(array_keys($this->appTable()), $known));

        $this->assertSame([], $unmanaged, sprintf(
            '%d app string(s) are not in the panel catalogue. Run '
            ."`php artisan translations:import --publish`.\n%s",
            count($unmanaged),
            implode("\n", array_slice($unmanaged, 0, 30)),
        ));
    }

    public function test_the_catalogue_publishes_all_three_languages(): void
    {
        $this->seedFromApp();

        $missing = TranslationCatalogue::missing();
        $total = collect($missing)->flatten()->count();

        $this->assertSame(0, $total, sprintf(
            "%d published translation(s) missing:\n%s",
            $total,
            json_encode($missing, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
        ));
    }

    /**
     * The delivery endpoint is what the phone actually reads. A complete
     * catalogue that serves an empty map helps nobody.
     */
    public function test_the_api_serves_a_full_catalogue_in_each_language(): void
    {
        $this->seedFromApp();
        $expected = count($this->appTable());

        foreach (PolicyDocuments::LOCALES as $locale) {
            $strings = $this->getJson("/api/v1/translations?lang={$locale}")
                ->assertOk()
                ->json('data.strings');

            $this->assertIsArray($strings);
            $this->assertGreaterThanOrEqual(
                $expected,
                count($strings),
                "The {$locale} catalogue is short of what the app declares.",
            );

            $blank = array_keys(array_filter($strings, fn ($v) => blank($v)));
            $this->assertSame([], $blank, "Blank {$locale} strings: ".implode(', ', array_slice($blank, 0, 10)));
        }
    }

    /**
     * A key rendered as-is is the visible symptom of a missing translation,
     * and it is what a student actually reports: "it says sp_private".
     */
    public function test_no_published_translation_is_just_its_own_key(): void
    {
        $this->seedFromApp();

        foreach (PolicyDocuments::LOCALES as $locale) {
            $strings = TranslationCatalogue::for($locale);

            $raw = [];
            foreach ($strings as $key => $value) {
                if (trim((string) $value) === $key) {
                    $raw[] = $key;
                }
            }

            $this->assertSame([], $raw,
                "These {$locale} strings are just their own key: ".implode(', ', $raw));
        }
    }

    // ---- the legal documents -------------------------------------------

    /**
     * The privacy policy and its moderation notice are shown before anyone
     * signs in and are the text people consent to, so a missing translation
     * there is a legal problem rather than a cosmetic one.
     */
    public function test_the_privacy_policy_exists_in_all_three_languages(): void
    {
        foreach (PolicyDocuments::LOCALES as $locale) {
            $path = PolicyDocuments::path('privacy', $locale);

            $this->assertFileExists($path);

            if ($locale !== 'tr') {
                $this->assertStringNotContainsString(
                    'privacy.md',
                    basename($path),
                    "The {$locale} privacy policy is falling back to the Turkish original."
                );
            }

            $this->assertGreaterThan(1000, strlen(File::get($path)),
                "The {$locale} privacy policy looks truncated.");
        }
    }

    public function test_every_legal_document_served_by_the_web_page_renders(): void
    {
        foreach (PolicyDocuments::LOCALES as $locale) {
            $this->get("/legal/privacy?lang={$locale}")
                ->assertOk()
                ->assertSee('<html lang="'.$locale.'"', false);
        }
    }
}
