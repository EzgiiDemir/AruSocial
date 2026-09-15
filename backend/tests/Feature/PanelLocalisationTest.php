<?php

namespace Tests\Feature;

use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\GranularPermissions;
use App\Services\Legal\PolicyDocuments;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The panels have to speak the language their staff chose.
 *
 * Both were English-only — every label, every confirmation, and all of
 * Filament's own chrome — while the people running them are the same
 * Turkish- and Russian-speaking staff the app is translated for. A delete
 * confirmation nobody can read is the one place a language gap does real
 * damage.
 */
class PanelLocalisationTest extends TestCase
{
    use RefreshDatabase;

    private function staff(string $language): User
    {
        $email = strtolower($language).'-staff@arucad.edu.tr';

        $user = User::firstOrCreate(
            ['email' => $email],
            ['name' => 'Panel Staff', 'password' => bcrypt('x')],
        );
        $user->preferred_language = $language;
        $user->save();

        RoleAssignment::updateOrCreate(
            ['email' => $email],
            [
                'role' => GranularPermissions::SUPER_ROLE,
                'permissions' => null,
                'assigned_by' => 'test',
                'assigned_at' => now(),
            ],
        );

        return $user;
    }

    // ---- the language files ---------------------------------------------

    /**
     * A key present in English but missing in Turkish silently falls back
     * to English, so the panel looks translated to whoever checked it in
     * English and is half-English to everybody else.
     */
    public function test_every_panel_string_exists_in_all_three_languages(): void
    {
        $flatten = function (array $rows, string $prefix = '') use (&$flatten): array {
            $out = [];
            foreach ($rows as $key => $value) {
                $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
                if (is_array($value)) {
                    $out = array_merge($out, $flatten($value, $path));
                } else {
                    $out[$path] = $value;
                }
            }

            return $out;
        };

        $byLocale = [];
        foreach (PolicyDocuments::LOCALES as $locale) {
            $path = lang_path($locale.'/panel.php');
            $this->assertFileExists($path, "No panel strings for {$locale}.");
            $byLocale[$locale] = $flatten(require $path);
        }

        $english = array_keys($byLocale['en']);

        foreach (['tr', 'ru'] as $locale) {
            $missing = array_values(array_diff($english, array_keys($byLocale[$locale])));
            $this->assertSame([], $missing, sprintf(
                "%d panel string(s) missing in %s:\n%s",
                count($missing),
                $locale,
                implode("\n", array_slice($missing, 0, 30)),
            ));

            $extra = array_values(array_diff(array_keys($byLocale[$locale]), $english));
            $this->assertSame([], $extra,
                "These {$locale} panel strings have no English original: ".implode(', ', $extra));

            $blank = array_keys(array_filter($byLocale[$locale], fn ($v) => blank($v)));
            $this->assertSame([], $blank, "Blank {$locale} panel strings: ".implode(', ', $blank));
        }
    }

    /**
     * A Turkish entry that is character-for-character the English one is
     * almost always a copy-paste that was never translated.
     */
    public function test_no_panel_string_is_left_as_its_english_original(): void
    {
        $en = require lang_path('en/panel.php');
        $tr = require lang_path('tr/panel.php');

        $untranslated = [];
        foreach ($en as $group => $rows) {
            if (! is_array($rows)) {
                continue;
            }
            foreach ($rows as $key => $value) {
                if (! is_string($value) || mb_strlen($value) < 10) {
                    continue;
                }
                if (($tr[$group][$key] ?? null) === $value) {
                    $untranslated[] = "{$group}.{$key}";
                }
            }
        }

        $this->assertSame([], $untranslated,
            'Still English in the Turkish file: '.implode(', ', $untranslated));
    }

    // ---- the middleware --------------------------------------------------

    /**
     * One account per case, deliberately. Signing a second account in
     * within one test trips `AuthenticateSession`, which invalidates the
     * session and answers 302 — a redirect that looks like an
     * authorisation failure and is really just the harness.
     *
     * @return array<string, array{string, string}>
     */
    public static function staffLanguages(): array
    {
        return [
            'Turkish' => ['TR', 'tr'],
            'English' => ['EN', 'en'],
            'Russian' => ['RU', 'ru'],
        ];
    }

    #[DataProvider('staffLanguages')]
    public function test_the_panel_renders_in_the_staff_members_language(
        string $stored,
        string $expected,
    ): void {
        $this->actingAs($this->staff($stored));
        Filament::setCurrentPanel('admin');

        $this->get('/admin/places/create')->assertSuccessful();

        $this->assertSame($expected, app()->getLocale(),
            "A staff member set to {$stored} did not get the {$expected} panel.");
    }

    /**
     * An unknown or empty preference must land somewhere readable rather
     * than on whatever Laravel's default happens to be.
     */
    public function test_an_unknown_preference_falls_back_to_turkish(): void
    {
        $user = $this->staff('TR');
        $user->preferred_language = 'DE';
        $user->save();

        $this->actingAs($user);
        Filament::setCurrentPanel('admin');
        $this->get('/admin/places/create')->assertSuccessful();

        $this->assertSame('tr', app()->getLocale());
    }

    // ---- what the staff member actually sees ----------------------------

    /**
     * Renders the real page and looks for real Turkish, which is the only
     * check that catches a label that was localised in the wrong file or a
     * key that resolves to itself.
     */
    public function test_a_turkish_staff_member_sees_turkish_labels(): void
    {
        $this->actingAs($this->staff('TR'));
        Filament::setCurrentPanel('admin');

        $this->get('/admin/places/create')
            ->assertSuccessful()
            ->assertSee('Mekân', false)
            ->assertSee('Enlem', false)
            ->assertSee('Boylam', false)
            ->assertDontSee('Latitude', false);
    }

    public function test_sidebar_translates_resource_labels_and_groups_instead_of_showing_keys(): void
    {
        app()->setLocale('tr');

        foreach (Filament::getPanel('admin')->getResources() as $resource) {
            $label = $resource::getNavigationLabel();
            $group = $resource::getNavigationGroup();

            $this->assertStringNotContainsString('panel.', $label, $resource.' has a raw navigation label.');
            $this->assertStringNotContainsString('_', $label, $resource.' has an internal key as its label.');

            if (is_string($group)) {
                $this->assertStringNotContainsString('panel.', $group, $resource.' has a raw navigation group.');
                $this->assertStringNotContainsString('_', $group, $resource.' has an internal key as its group.');
            }
        }
    }

    public function test_a_russian_staff_member_sees_russian_labels(): void
    {
        $this->actingAs($this->staff('RU'));
        Filament::setCurrentPanel('admin');

        $this->get('/admin/places/create')
            ->assertSuccessful()
            ->assertSee('Широта', false)
            ->assertSee('Долгота', false)
            ->assertDontSee('Latitude', false);
    }

    /**
     * Filament ships its own translations, so setting the locale should
     * also carry its buttons and chrome — which is most of the words on
     * the screen.
     */
    public function test_filament_ships_the_languages_this_product_needs(): void
    {
        foreach (PolicyDocuments::LOCALES as $locale) {
            $this->assertDirectoryExists(
                base_path("vendor/filament/filament/resources/lang/{$locale}"),
                "Filament has no {$locale} translations, so its own chrome would stay English."
            );
        }
    }

    /**
     * The label a student would never see but staff read constantly.
     */
    public function test_no_panel_language_file_has_a_stray_untranslated_key(): void
    {
        foreach (PolicyDocuments::LOCALES as $locale) {
            $source = File::get(lang_path($locale.'/panel.php'));

            $this->assertStringNotContainsString('TODO', $source,
                "The {$locale} panel strings still have a TODO in them.");
        }
    }
}
