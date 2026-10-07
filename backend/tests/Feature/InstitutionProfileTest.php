<?php

namespace Tests\Feature;

use App\Filament\Pages\InstitutionProfilePage;
use App\Services\Ai\AskPromptBuilder;
use App\Services\Ai\InstitutionProfile;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * What the assistant knows about ARUCAD without having to look it up.
 */
class InstitutionProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function profile(): InstitutionProfile
    {
        return app(InstitutionProfile::class);
    }

    /** The shipped identity is present out of the box, with no setup. */
    public function test_the_shipped_identity_names_the_university(): void
    {
        $text = $this->profile()->text();

        $this->assertStringContainsString('Arkin University of Creative Arts and Design', $text);
        $this->assertStringContainsString('2017', $text);
        $this->assertStringContainsString('Girne', $text);
    }

    /**
     * The point of the whole thing: it reaches the model on every question,
     * including one that retrieves nothing at all.
     */
    public function test_the_identity_is_in_the_prompt_even_with_no_sources(): void
    {
        $prompt = app(AskPromptBuilder::class)->text('kimsiniz');

        $this->assertStringContainsString('ARUCAD KURUM KİMLİĞİ', $prompt);
        $this->assertStringContainsString('Arkin University of Creative Arts and Design', $prompt);
    }

    /**
     * It must sit in the TRUSTED layer.
     *
     * Inside the fenced block the rules tell the model to treat it as quoted
     * data it may not act on, which is right for a crawled page and wrong for
     * the university's own statement of who it is.
     */
    public function test_the_identity_is_not_inside_the_untrusted_fence(): void
    {
        $prompt = app(AskPromptBuilder::class)->text('kimsiniz');

        $identityAt = mb_strpos($prompt, 'ARUCAD KURUM KİMLİĞİ');
        $fenceAt = mb_strpos($prompt, '<<<ARUCAD_RETRIEVED_CONTENT');

        $this->assertNotFalse($identityAt);
        if ($fenceAt !== false) {
            $this->assertLessThan($fenceAt, $identityAt);
        }
    }

    /** An operator's text replaces the shipped one. */
    public function test_an_edited_profile_is_what_the_model_sees(): void
    {
        $this->profile()->save('## Kim\'iz'."\nARUCAD bir sanat üniversitesidir. Test cümlesi XYZZY.");

        $prompt = app(AskPromptBuilder::class)->text('kimsiniz');

        $this->assertStringContainsString('XYZZY', $prompt);
        $this->assertStringNotContainsString('Arkin University of Creative Arts and Design', $prompt);
    }

    /**
     * Editing it must invalidate the answer cache.
     *
     * Otherwise correcting a wrong fact changes nothing a student can see
     * until the cache happens to expire, which is the sort of bug that gets
     * diagnosed as "the edit did not save".
     */
    public function test_editing_the_profile_changes_its_version(): void
    {
        $before = $this->profile()->version();
        $this->profile()->save('Tamamen farklı bir metin.');

        $this->assertNotSame($before, $this->profile()->version());
    }

    /** Emptying it is allowed and simply removes the block. */
    public function test_clearing_the_override_restores_the_shipped_text(): void
    {
        $this->profile()->save('Geçici metin.');
        $this->assertSame('Geçici metin.', $this->profile()->text());

        $this->profile()->save(null);

        $this->assertStringContainsString('Arkin University of Creative Arts and Design', $this->profile()->text());
    }

    /** A pasted brochure is truncated rather than eating the context window. */
    public function test_an_oversized_profile_is_capped(): void
    {
        $huge = rtrim(str_repeat('uzun metin. ', 2000));
        $this->profile()->save($huge);

        $block = $this->profile()->block();

        // The stored text is kept in full; only what reaches the prompt is
        // capped, plus the fixed header and note around it.
        $this->assertSame($huge, $this->profile()->text());
        $this->assertLessThan(4200, mb_strlen($block));
        $this->assertStringContainsString('uzun metin', $block);
    }

    // ------------------------------------------------------------- panel

    public function test_a_student_cannot_open_the_page(): void
    {
        $this->actingAsRole('student');

        $this->assertFalse(InstitutionProfilePage::canAccess());
    }

    public function test_an_admin_can_open_and_save(): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAsRole('superAdmin');

        Livewire::test(InstitutionProfilePage::class)
            ->assertOk()
            ->set('profile', 'ARUCAD test kimliği QWERTY.')
            ->callAction('save')
            ->assertHasNoActionErrors();

        $this->assertStringContainsString('QWERTY', $this->profile()->text());
    }
}
