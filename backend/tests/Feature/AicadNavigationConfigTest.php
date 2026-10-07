<?php

namespace Tests\Feature;

use App\Services\Translations\AppStringsParser;
use Tests\TestCase;

/**
 * The trusted app destinations (config/aicad_navigation.php) must be real:
 * every key is a string the app ships in all three languages and some
 * Flutter screen still uses, and every parent is itself a destination. The
 * app has no route table, so this is the guard against a renamed or removed
 * screen leaving AICAD sending students somewhere that does not exist.
 */
class AicadNavigationConfigTest extends TestCase
{
    public function test_every_trusted_destination_is_a_real_app_string_used_by_a_screen(): void
    {
        $destinations = (array) config('aicad_navigation.destinations');
        $this->assertNotEmpty($destinations);

        $strings = AppStringsParser::parseFile();
        $lib = base_path('../frontend/lib');
        if ($strings === [] || ! is_dir($lib)) {
            $this->markTestSkipped('The Flutter app is not checked out next to the backend.');
        }
        $dart = '';
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($lib, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'dart' && ! str_contains($file->getPathname(), 'l10n')) {
                $dart .= file_get_contents($file->getPathname());
            }
        }

        foreach ($destinations as $id => $d) {
            $key = $d['key'] ?? null;
            $this->assertIsString($key, "$id has no translation key");
            $this->assertEqualsCanonicalizing(['tr', 'en', 'ru'], array_keys($strings[$key] ?? []), "$id: '$key' is not in app_strings.dart in tr, en and ru");
            $this->assertStringContainsString("t('$key')", $dart, "$id: no screen uses '$key' any more");
            if (isset($d['parent'])) {
                $this->assertArrayHasKey($d['parent'], $destinations, "$id: parent '{$d['parent']}' is not a destination");
            }
        }
    }
}
