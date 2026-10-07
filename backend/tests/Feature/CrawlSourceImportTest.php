<?php

namespace Tests\Feature;

use App\Filament\Resources\CrawlSources\Pages\ListCrawlSources;
use App\Models\CrawlSource;
use App\Support\CrawlSourceImport;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Bulk-adding crawl sources from the lists operators actually have.
 */
class CrawlSourceImportTest extends TestCase
{
    use RefreshDatabase;

    private function import(): CrawlSourceImport
    {
        return app(CrawlSourceImport::class);
    }

    /**
     * The point of the feature: a list of pages is not a list of sources.
     * Four hundred arucad.edu.tr URLs are one crawl source, not four hundred.
     */
    public function test_many_page_urls_collapse_to_their_hosts(): void
    {
        $result = $this->import()->apply(implode("\n", [
            'https://arucad.edu.tr/kutuphane/',
            'https://arucad.edu.tr/en/library/',
            'https://www.arucad.edu.tr/burslar-ve-ucretler/',
            'https://aday.arucad.edu.tr/rt-program/mimarlik/',
        ]));

        $this->assertSame(2, $result['created']);
        $this->assertEqualsCanonicalizing(
            ['arucad.edu.tr', 'aday.arucad.edu.tr'],
            CrawlSource::pluck('domain')->all(),
        );
    }

    /** A bare domain list works too — no scheme, no header. */
    public function test_a_plain_domain_list_is_accepted(): void
    {
        $this->import()->apply("arucad.edu.tr\nlibrary.arucad.edu.tr\n");

        $this->assertSame(2, CrawlSource::count());
        $this->assertTrue(CrawlSource::find('arucad.edu.tr')->enabled);
        $this->assertSame(CrawlSource::ACCESS_GLOBAL, CrawlSource::find('arucad.edu.tr')->access);
    }

    /** A CSV header carries the other columns. */
    public function test_a_csv_header_sets_label_keys_access_and_enabled(): void
    {
        $this->import()->apply(implode("\n", [
            'domain,label,keys,access,enabled',
            'aday.arucad.edu.tr,Admissions,"burs, ücret, başvuru",global,1',
            'sis.arucad.edu.tr,SIS,,local,0',
        ]));

        $admissions = CrawlSource::find('aday.arucad.edu.tr');
        $this->assertSame('Admissions', $admissions->label);
        $this->assertEqualsCanonicalizing(['burs', 'ucret', 'basvuru'], $admissions->keyTerms());

        $sis = CrawlSource::find('sis.arucad.edu.tr');
        $this->assertSame(CrawlSource::ACCESS_LOCAL, $sis->access);
        $this->assertFalse($sis->enabled);
    }

    /**
     * Re-importing must not wipe what an operator typed.
     *
     * The keys are written by hand in the panel and are the part of a source
     * that cannot be recovered from a URL list. Someone pasting their list
     * again to add one new site would otherwise erase all of them.
     */
    public function test_reimporting_a_bare_list_keeps_existing_keys(): void
    {
        CrawlSource::create([
            'domain' => 'arucad.edu.tr',
            'label' => 'Main site',
            'keys' => 'burs, kütüphane',
            'access' => CrawlSource::ACCESS_GLOBAL,
            'enabled' => true,
        ]);

        $result = $this->import()->apply("arucad.edu.tr\nyeni.arucad.edu.tr\n");

        $this->assertSame(1, $result['created']);
        $this->assertSame(1, $result['unchanged']);

        $main = CrawlSource::find('arucad.edu.tr');
        $this->assertSame('burs, kütüphane', $main->keys);
        $this->assertSame('Main site', $main->label);
    }

    /** Lines that are not addresses are skipped rather than stored. */
    public function test_headings_comments_and_e_mail_addresses_are_skipped(): void
    {
        $result = $this->import()->apply(implode("\n", [
            '# Programs',
            'Bölümler',
            'info@arucad.edu.tr',
            '',
            'arucad.edu.tr',
        ]));

        $this->assertSame(1, $result['created']);
        $this->assertSame(['arucad.edu.tr'], CrawlSource::pluck('domain')->all());
    }

    /**
     * The button exists on the page and actually imports.
     *
     * The parser above is pure PHP and would pass whether or not it were
     * wired to anything, so this drives the real Filament action: an API that
     * is wrong here fails the test rather than only failing in the browser.
     */
    public function test_the_panel_action_imports_a_pasted_list(): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAsRole('superAdmin');

        Livewire::test(ListCrawlSources::class)
            ->callAction('bulkImport', [
                'text' => 'https://arucad.edu.tr/kutuphane/
library.arucad.edu.tr
',
            ])
            ->assertHasNoActionErrors();

        $this->assertEqualsCanonicalizing(
            ['arucad.edu.tr', 'library.arucad.edu.tr'],
            CrawlSource::pluck('domain')->all(),
        );
    }

    /** A port, a trailing slash and capitals name the same host. */
    public function test_a_host_is_normalised(): void
    {
        $import = $this->import();

        $this->assertSame('arucad.edu.tr', $import->host('HTTPS://WWW.ARUCAD.EDU.TR/en/'));
        $this->assertSame('arucad.edu.tr', $import->host('arucad.edu.tr:8080/path'));
        $this->assertNull($import->host('not a domain'));
        $this->assertNull($import->host(''));
    }
}
