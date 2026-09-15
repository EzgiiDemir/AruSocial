<?php

namespace App\Http\Controllers\Web;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\File;

class LegalController extends Controller
{
    /** Titles per language, so the page heading matches the body. */
    private const PRIVACY_TITLES = [
        'tr' => 'Gizlilik Politikası',
        'en' => 'Privacy Policy',
        'ru' => 'Политика конфиденциальности',
    ];

    private const GUIDELINES_TITLES = [
        'tr' => 'Topluluk Kuralları',
        'en' => 'Community Guidelines',
        'ru' => 'Правила сообщества',
    ];

    public function privacy(Request $request): Response
    {
        $language = $this->language($request);

        return $this->page(
            self::PRIVACY_TITLES[$language],
            $this->localisedPath('privacy', $language),
            $language,
        );
    }

    /**
     * The rules students agree to alongside the privacy policy.
     *
     * Its own published page for the same reason the privacy policy has
     * one: the consent checkbox names both documents, so both have to be
     * readable by someone who has not installed the app — an app store
     * reviewer, or a student who has just been locked out.
     */
    public function guidelines(Request $request): Response
    {
        $language = $this->language($request);

        return $this->page(
            self::GUIDELINES_TITLES[$language],
            $this->localisedPath('community-guidelines', $language),
            $language,
        );
    }

    /**
     * Which language to serve: `?lang=` if it names one we have, else
     * Turkish.
     *
     * Deliberately NOT Accept-Language. This is the governing legal text,
     * and which version a bare `/legal/privacy` returns should not depend
     * on a browser header that varies by device — a link pasted into a
     * disciplinary file has to resolve to the same document tomorrow.
     * Accept-Language also made the default unpredictable in practice: it
     * silently turned the bare URL English and broke a test that reads it
     * as the Turkish policy.
     *
     * The app passes `?lang=` because it knows what the student chose.
     */
    private function language(Request $request): string
    {
        $asked = strtolower(substr((string) $request->query('lang', ''), 0, 2));

        return in_array($asked, ['tr', 'en', 'ru'], true) ? $asked : 'tr';
    }

    /**
     * The translated file, or the Turkish original when there is none.
     *
     * Turkish is the fallback because it is the version legal review
     * approves and the one the translations defer to. A missing
     * translation must serve the governing text, not an error page.
     */
    private function localisedPath(string $name, string $language): string
    {
        if ($language !== 'tr') {
            $translated = base_path("../docs/legal/{$name}.{$language}.md");
            if (File::exists($translated)) {
                return $translated;
            }
        }

        return base_path("../docs/legal/{$name}.md");
    }

    public function terms(): Response
    {
        return $this->page(
            'Kullanım Şartları',
            base_path('../docs/legal/terms.md')
        );
    }

    /**
     * Published safety contact.
     *
     * Its own page, reachable without signing in, because an app carrying
     * user-generated content has to state plainly where someone reports
     * abuse and how quickly they will hear back — and because the person
     * who most needs it may be the one who has just been locked out or
     * driven off the platform.
     */
    public function safety(): Response
    {
        return $this->page(
            'Güvenlik ve İçerik Bildirimi',
            base_path('../docs/legal/safety.md')
        );
    }

    private function page(string $title, string $path, string $language = 'tr'): Response
    {
        $body = File::exists($path)
            ? $this->render(File::get($path))
            : '<p>Bu metin henüz yayınlanmadı.</p>';

        // The `lang` attribute has to match what is actually on the page:
        // it is what a screen reader picks a voice from, and it was
        // hardcoded `tr` for every document.
        $html = '<!DOCTYPE html><html lang="'.e($language).'"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<title>'.e($title).' — ARUVERSE</title>'
            .'<style>'
            .'body{font-family:system-ui,-apple-system,sans-serif;max-width:720px;'
            .'margin:0 auto;padding:40px 20px 80px;line-height:1.6;color:#14181f;'
            .'background:#fbfbfc}'
            .'h1{font-size:26px;line-height:1.2;margin:0 0 6px}'
            .'h2{font-size:18px;margin:32px 0 8px;padding-top:14px;'
            .'border-top:1px solid #e3e6ea}'
            .'h3{font-size:15px;margin:22px 0 6px}'
            .'p,li{max-width:66ch}ul{padding-left:20px}li{margin:4px 0}'
            .'code{background:#eef1f5;padding:2px 5px;border-radius:4px;'
            .'font-size:.92em}'
            .'a{color:#1b4a9c}'
            .'@media(prefers-color-scheme:dark){body{background:#0e1116;color:#e9edf3}'
            .'h2{border-top-color:#2a323e}code{background:#1d242e}a{color:#6e9be8}}'
            .'</style>'
            .'</head><body><h1>'.e($title).'</h1>'.$body.'</body></html>';

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * A very small Markdown subset, rendered safely.
     *
     * The document is escaped *first* and only tags generated here are
     * added afterwards, so nothing in the source file can introduce
     * markup — these are policy documents rather than user input, but a
     * public unauthenticated page is the wrong place to rely on that.
     *
     * Deliberately not a Markdown library: the legal texts use headings,
     * bullets, bold, inline code and links, and pulling in a parser to
     * cover the rest of the syntax would be more surface than the job
     * needs. The previous version showed the raw file inside a `<pre>`,
     * which is the page an App Store reviewer reads when checking that
     * the safety contact is published.
     */
    private function render(string $markdown): string
    {
        $out = [];
        $inList = false;
        // These documents wrap paragraphs across several lines, so lines
        // are gathered and flushed on a blank line, heading or bullet —
        // otherwise every wrapped line becomes its own paragraph and the
        // text renders as a ragged column.
        $paragraph = [];

        $flush = function () use (&$paragraph, &$out): void {
            if ($paragraph !== []) {
                $out[] = '<p>'.implode(' ', $paragraph).'</p>';
                $paragraph = [];
            }
        };

        foreach (preg_split('/\R/', $markdown) as $line) {
            $line = e(rtrim($line));

            // Inline: **bold**, `code`, and [text](/path) for internal links.
            $line = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $line);
            $line = preg_replace('/`([^`]+)`/u', '<code>$1</code>', $line);
            $line = preg_replace(
                '~\[([^\]]+)\]\((/[a-z0-9/_-]*)\)~i',
                '<a href="$2">$1</a>',
                $line,
            );

            $closeList = function () use (&$inList, &$out): void {
                if ($inList) {
                    $out[] = '</ul>';
                    $inList = false;
                }
            };

            if (trim($line) === '') {
                $flush();
                $closeList();

                continue;
            }
            if (str_starts_with($line, '- ')) {
                $flush();
                if (! $inList) {
                    $out[] = '<ul>';
                    $inList = true;
                }
                $out[] = '<li>'.substr($line, 2).'</li>';

                continue;
            }

            // The file's own `# Title` duplicates the page heading, which
            // the caller already rendered.
            if (str_starts_with($line, '### ')) {
                $flush();
                $closeList();
                $out[] = '<h3>'.substr($line, 4).'</h3>';
            } elseif (str_starts_with($line, '## ')) {
                $flush();
                $closeList();
                $out[] = '<h2>'.substr($line, 3).'</h2>';
            } elseif (str_starts_with($line, '# ')) {
                $flush();
                $closeList();
            } else {
                $closeList();
                $paragraph[] = $line;
            }
        }

        $flush();
        if ($inList) {
            $out[] = '</ul>';
        }

        return implode("\n", $out);
    }
}
