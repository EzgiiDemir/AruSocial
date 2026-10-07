<?php

namespace App\Services\Ai;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Cache;

/**
 * Who ARUCAD is, as the university states it — not as a crawler found it.
 *
 * Retrieval answers "what does a page say about X". It cannot answer "who are
 * you", because that fact is not on any one page: it is spread across a
 * homepage, an about page and two hundred news posts, and the ranker picks
 * whichever of those matched the words. Asked plainly who ARUCAD is, the
 * assistant was assembling an answer out of whatever news article scored
 * highest, which is how it ended up confident and wrong.
 *
 * So this is a separate, small, trusted layer. It sits with the metadata and
 * the personal data — the things the system asserts — and NOT inside the
 * fenced retrieved block, because the university's own statement of itself is
 * not untrusted input and must not be treated as such.
 *
 * Kept deliberately short and free of anything dated. Fees, quotas, deadlines
 * and scholarship percentages are NOT identity: they change every year, and a
 * stale figure stated with the authority of this block is worse than no
 * figure at all. Those come from the crawled pages, where they carry a date.
 */
class InstitutionProfile
{
    public const SETTING_KEY = 'arucad.profile';

    private const CACHE_KEY = 'ai:institution-profile';

    /**
     * The most this block may contribute to a prompt.
     *
     * Every question pays for it, so it competes with the retrieved sources
     * for the same context window. Large enough for the identity above,
     * small enough that an operator pasting an entire brochure degrades the
     * answer's grounding rather than silently blowing the context.
     */
    private const MAX_CHARS = 3200;

    /** The editable text: the operator's version, or the shipped default. */
    public function text(): string
    {
        return Cache::remember(self::CACHE_KEY, now()->addMinutes(10), function (): string {
            $stored = AppSetting::getValue(self::SETTING_KEY);

            return is_string($stored) && trim($stored) !== ''
                ? trim($stored)
                : $this->shipped();
        });
    }

    /** The default that ships with the application. */
    public function shipped(): string
    {
        $path = resource_path('knowledge/arucad-profile.md');

        return is_file($path) ? trim((string) file_get_contents($path)) : '';
    }

    public function save(?string $text): void
    {
        AppSetting::setValue(self::SETTING_KEY, $text === null ? null : trim($text));
        $this->forget();
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * The prompt block, or '' when an operator has emptied the profile.
     *
     * Labelled as the university's own statement so the model treats a
     * conflict with a crawled page as "the page is newer or narrower", not as
     * "two sources disagree and I will pick one".
     */
    public function block(): string
    {
        $text = $this->text();
        if ($text === '') {
            return '';
        }

        if (mb_strlen($text) > self::MAX_CHARS) {
            $text = rtrim(mb_substr($text, 0, self::MAX_CHARS));
        }

        /*
         * The trailing note earns its place twice.
         *
         * Language: this block is Turkish and it is long, which on its own
         * drags the answer into Turkish — measured, "Who is ARUCAD?" came
         * back entirely in Turkish the moment it was added. The per-request
         * language directive is not enough against this much text.
         *
         * Precedence: identity from here, dated facts from the pages. Without
         * that split, an operator who pastes a fee into this box has created
         * a figure the assistant repeats long after it changed.
         */
        $note = <<<'NOTE'
            Bu bölüm YALNIZCA referans bilgisidir; hangi dilde yazıldığı cevabın
            dilini BELİRLEMEZ. Kullanıcı hangi dilde sorduysa o dilde cevap ver.
            Bu bilgi kurumun kendi beyanıdır, alıntılanmış web içeriği değildir:
            kimlik sorularında (ad, kuruluş, kampüs, fakülte, felsefe) önce buna
            dayan; dönemlik bilgide (ücret, kontenjan, takvim) tarihli sayfayı
            esas al.
            NOTE;

        return "\n\nARUCAD KURUM KİMLİĞİ (üniversitenin kendi beyanı, güvenilir):\n"
            .$text."\n\n".$note;
    }

    /** Fingerprint for the answer cache: an edited profile must invalidate it. */
    public function version(): string
    {
        return substr(sha1($this->text()), 0, 8);
    }
}
