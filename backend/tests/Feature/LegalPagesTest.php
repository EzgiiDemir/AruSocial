<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The pages an app-store reviewer reads.
 *
 * Apple's guideline 1.2 asks a user-generated-content app for four
 * things, and three of them are only credible if they are written down
 * somewhere public: a zero-tolerance policy for objectionable content, a
 * way to report it, a way to block abusive users, and a published contact
 * with a stated response time.
 *
 * The mechanisms all exist in the app. These tests are about the claims
 * being reachable and accurate, because a compliance page that 404s or
 * quietly contradicts the implementation is worse than none — it is a
 * promise nobody is keeping.
 */
class LegalPagesTest extends TestCase
{
    public static function pages(): array
    {
        return [
            'safety' => ['/legal/safety'],
            'terms' => ['/legal/terms'],
            'privacy' => ['/legal/privacy'],
        ];
    }

    /** Unauthenticated on purpose: someone suspended must still reach it. */
    #[DataProvider('pages')]
    public function test_the_page_is_public(string $path): void
    {
        $this->get($path)
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    #[DataProvider('pages')]
    public function test_the_page_renders_as_html_not_raw_markdown(string $path): void
    {
        $body = $this->get($path)->getContent();

        $this->assertStringContainsString('<h2>', $body,
            'Headings were not rendered — the document is showing as raw text.');
        $this->assertStringNotContainsString('<pre>', $body);
        $this->assertStringNotContainsString('## ', $body);
    }

    /**
     * The app ships its own copy of these documents as bundled assets,
     * because a student reading the safety contact may have no network —
     * or may be reading it precisely because something is wrong.
     *
     * Two copies of a legal text diverge silently, and the version a
     * person actually reads would be the stale one. This fails the build
     * instead.
     */
    #[DataProvider('documents')]
    public function test_the_bundled_copy_matches_the_published_one(string $file): void
    {
        $web = base_path('../docs/legal/'.$file);
        $app = base_path('../frontend/assets/legal/'.$file);

        $this->assertFileExists($app,
            "The app does not bundle {$file}, so Settings would show nothing.");

        $this->assertSame(
            str_replace("\r\n", "\n", (string) file_get_contents($web)),
            str_replace("\r\n", "\n", (string) file_get_contents($app)),
            "docs/legal/{$file} and frontend/assets/legal/{$file} have drifted. "
            .'Copy the docs/ version into the app assets.',
        );
    }

    public static function documents(): array
    {
        return [
            'safety.md' => ['safety.md'],
            'terms.md' => ['terms.md'],
            'privacy.md' => ['privacy.md'],
        ];
    }

    /** The four things guideline 1.2 asks for, in the terms. */
    public function test_the_terms_state_zero_tolerance_reporting_and_blocking(): void
    {
        $body = $this->get('/legal/terms')->getContent();

        $this->assertStringContainsString('sıfır tolerans', $body);
        $this->assertStringContainsString('Bildir', $body);
        $this->assertStringContainsString('ngelle', $body);   // engelle / Engelle
        $this->assertStringContainsString('24 saat', $body);
    }

    public function test_the_safety_page_publishes_a_contact_and_a_response_time(): void
    {
        $body = $this->get('/legal/safety')->getContent();

        $this->assertStringContainsString('guvenlik@arucad.edu.tr', $body);
        $this->assertStringContainsString('112', $body);
        $this->assertStringContainsString('24 saat', $body);
    }

    /**
     * The appeal route is the one claim most likely to rot: the terms
     * used to say decisions were final and appeals went through student
     * affairs, which stopped being true once in-app appeals shipped.
     */
    public function test_the_terms_describe_the_in_app_appeal(): void
    {
        $body = $this->get('/legal/terms')->getContent();

        $this->assertStringContainsString('itiraz', $body);
        $this->assertStringNotContainsString('Moderasyon kararı nihaidir', $body,
            'The terms still say moderation decisions are final, but appeals exist.');
    }

    /**
     * Moderation runs in-house by default, and it has to stay that way
     * unless a deployment deliberately turns the remote provider on.
     *
     * The privacy policy used to state this outright; the current wording
     * does not, and instead permits sharing with "service providers that
     * help us operate and secure the app". The configuration guarantee is
     * still worth holding on its own — a deployment that forgets an env
     * var must not start sending student content to a third party — so the
     * check remains, without the text assertion it used to pair with.
     */
    public function test_remote_moderation_is_off_unless_deliberately_enabled(): void
    {
        // Asserted against the config file's own default rather than the
        // resolved value, because `phpunit.xml` deliberately forces this
        // ON so the remote provider's code path stays covered by tests.
        // The claim in the notice is about what a deployment does when
        // nobody sets the variable, and that is what this reads.
        $source = file_get_contents(config_path('services.php'));
        $this->assertMatchesRegularExpression(
            "/env\(\s*'MODERATION_OPENAI_ENABLED'\s*,\s*'false'\s*\)/",
            (string) $source,
            'The privacy notice says moderation stays in-house, but the remote '
            .'provider would be enabled on a deployment that does not set the '
            .'variable. A privacy claim that depends on remembering an env var '
            .'is not a privacy claim.',
        );
    }

    // ---- the content and moderation notice ---------------------------

    /**
     * The notice has to exist in every language the app offers, not only
     * the one the policy happens to be written in.
     *
     * A student reading the Russian privacy policy and finding no mention
     * of moderation has not been told that their posts are reviewed —
     * whatever the Turkish version says.
     *
     * @return array<string, list<string>> language => a phrase only that
     *                                     translation contains
     */
    public static function noticeByLanguage(): array
    {
        return [
            'turkish' => ['tr', '3. İçerik Moderasyonu'],
            'english' => ['en', '3. Content Moderation'],
            'russian' => ['ru', '3. Модерация контента'],
        ];
    }

    #[DataProvider('noticeByLanguage')]
    public function test_the_moderation_notice_is_served_in_each_language(
        string $language,
        string $heading,
    ): void {
        $body = $this->get('/legal/privacy?lang='.$language)->assertOk()->getContent();

        $this->assertStringContainsString($heading, (string) $body);
        $this->assertStringContainsString('lang="'.$language.'"', (string) $body,
            'The page declares a different language than it is written in.');
    }

    /**
     * Each translation must actually carry the three obligations, not just
     * a heading: review by automated systems and moderators, the user's
     * own responsibility, and referral for disciplinary proceedings.
     *
     * @return array<string, list<string>>
     */
    public static function noticeSubstance(): array
    {
        return [
            'turkish' => ['tr', [
                'otomatik güvenlik sistemleri',
                'üniversite birimlerine',
                'insan incelemesi',
            ]],
            'english' => ['en', [
                'automated safety systems',
                'university authorities',
                'human review',
            ]],
            'russian' => ['ru', [
                'автоматизированными системами безопасности',
                'органам университета',
                'участием человека',
            ]],
        ];
    }

    /** @param list<string> $phrases */
    #[DataProvider('noticeSubstance')]
    public function test_each_translation_carries_the_same_obligations(
        string $language,
        array $phrases,
    ): void {
        $body = (string) $this->get('/legal/privacy?lang='.$language)->getContent();

        foreach ($phrases as $phrase) {
            $this->assertStringContainsString($phrase, $body,
                "The {$language} notice is missing: {$phrase}");
        }
    }

    // ---- the community guidelines -------------------------------------

    /**
     * The consent checkbox names two documents, so both have to be
     * readable by someone who has not installed the app — an app store
     * reviewer, or a student who has just been locked out and is looking
     * for how to appeal.
     *
     * @return array<string, list<string>>
     */
    public static function guidelinesByLanguage(): array
    {
        return [
            'turkish' => ['tr', 'Topluluk Kuralları', 'kalıcı olarak kapatmak'],
            'english' => ['en', 'Community Guidelines', 'permanently disable'],
            'russian' => ['ru', 'Правила сообщества', 'навсегда закрыть'],
        ];
    }

    #[DataProvider('guidelinesByLanguage')]
    public function test_the_community_guidelines_are_published_in_each_language(
        string $language,
        string $title,
        string $enforcement,
    ): void {
        $body = (string) $this->get('/legal/community-guidelines?lang='.$language)
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString($title, $body);
        $this->assertStringContainsString('lang="'.$language.'"', $body,
            'The page declares a different language than it is written in.');

        // The list of what can happen to an account is the part an appeal
        // is measured against, so a page without it is not the document.
        $this->assertStringContainsString($enforcement, $body,
            "The {$language} guidelines do not say what can happen to an account.");
    }

    /**
     * Both documents have to say how to challenge a decision. A rule set
     * with no route of appeal is what app store review rejects.
     */
    public function test_the_guidelines_describe_how_to_appeal(): void
    {
        $body = (string) $this->get('/legal/community-guidelines')->getContent();

        $this->assertStringContainsString('İtiraz', $body);
    }

    /** An unknown language gets the governing text, not an error. */
    public function test_an_unsupported_language_falls_back_to_turkish(): void
    {
        $body = (string) $this->get('/legal/privacy?lang=de')->assertOk()->getContent();

        $this->assertStringContainsString('3. İçerik Moderasyonu', $body);
    }

    /**
     * Video was removed on 14 September 2026. A privacy policy still
     * describing video moderation is a statement about processing that no
     * longer happens.
     */
    public function test_the_policy_does_not_describe_video_moderation(): void
    {
        foreach (['tr', 'en', 'ru'] as $language) {
            $body = (string) $this->get('/legal/privacy?lang='.$language)->getContent();

            $this->assertStringNotContainsString('video moderasyonu ARUCAD', $body);
            $this->assertStringNotContainsString('Text, image and video moderation', $body);
        }
    }
}
