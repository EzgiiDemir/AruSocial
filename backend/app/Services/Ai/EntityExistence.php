<?php

namespace App\Services\Ai;

use App\Models\Club;
use App\Models\Sport;
use App\Services\Knowledge\CampusVocabulary;
use App\Support\TextFold;
use Illuminate\Support\Collection;

/**
 * "Is there an X?" answered from the list, never by the model.
 *
 * This is the failure that does the most damage, measured across 194 real
 * questions:
 *
 *   "satranç kulübü var mı"   -> "Evet, ARUCAD'de satranç kulübü vardır",
 *                                 with invented activities. There are 19
 *                                 clubs and chess is not one of them.
 *   "havuz var mı"            -> "Evet, havuz bulunmaktadır" — inferred from
 *                                 a café called "Havuz Başı Kafeterya".
 *   "hukuk okuyabilir miyim"  -> "Evet, hukuk okuyabilirsin", from a COURSE
 *                                 called "İletişim Hukuku ve Etik".
 *
 * The pattern is the same each time: retrieval finds something with the word
 * in it, and the model reads presence of the word as existence of the thing.
 * No prompt wording fixes that reliably, and it does not need to — a club
 * either has a row or it does not, and that is a lookup.
 *
 * Only categories with a genuinely complete list belong here. Clubs and
 * sports are maintained in the admin panel and are authoritative. Anything
 * else falls through to normal retrieval, because answering "no" from a
 * partial list would be its own kind of confident wrong.
 */
class EntityExistence
{
    /** Phrases that make a question about whether something exists. */
    private const EXISTENCE = [
        'var mi', 'varmi', 'var midir', 'bulunuyor mu', 'mevcut mu', 'oluyor mu',
        'is there', 'are there', 'do you have', 'does arucad have', 'is it available',
        'est li', 'есть ли', 'имеется ли',
        // "Can I study X" asks the same thing about a programme, and leaving
        // it out meant "hukuk okuyabilir miyim" never reached the check that
        // exists for it.
        'okuyabilir miyim', 'okuyabilirmiyim', 'okumak istiyorum', 'egitimi var',
        'can i study', 'do you teach', 'могу ли я изучать',
    ];

    /** Words naming the category, and the words to strip when reading the name. */
    private const CLUB_WORDS = ['kulubu', 'kulup', 'kulubunuz', 'club', 'clubs', 'клуб', 'клуба'];

    private const SPORT_WORDS = ['takimi', 'takim', 'spor', 'sport', 'sports', 'спорт'];

    /** Words that make a question about whether a programme is taught here. */
    private const PROGRAMME_WORDS = [
        'bolum', 'bolumu', 'program', 'programi', 'okuyabilir', 'okumak',
        'egitimi var', 'fakultesi',
        'department', 'programme', 'major', 'can i study', 'do you teach',
        'факультет', 'специальность', 'могу ли я изучать',
    ];

    /**
     * An answer when the question asks whether a specific club exists, else
     * null so the normal pipeline handles it.
     */
    public function answer(string $query, string $language): ?string
    {
        if (! $this->asksExistence($query)) {
            return null;
        }

        $q = TextFold::fold($query);

        if ($this->mentions($q, self::CLUB_WORDS)) {
            return $this->clubAnswer($q, $language);
        }

        if ($this->mentions($q, self::SPORT_WORDS)) {
            return $this->sportAnswer($q, $language);
        }

        if ($this->mentions($q, self::PROGRAMME_WORDS)) {
            return $this->programmeAnswer($q, $language);
        }

        return null;
    }

    private function asksExistence(string $query): bool
    {
        $q = TextFold::fold($query);
        foreach (self::EXISTENCE as $marker) {
            if (str_contains($q, TextFold::fold($marker))) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $words */
    private function mentions(string $folded, array $words): bool
    {
        foreach ($words as $word) {
            if (str_contains($folded, TextFold::fold($word))) {
                return true;
            }
        }

        return false;
    }

    private function clubAnswer(string $folded, string $language): ?string
    {
        $clubs = Club::query()->orderBy('name')->get(['name']);
        if ($clubs->isEmpty()) {
            return null;
        }

        $match = $this->bestMatch($clubs, $folded);

        if ($match !== null) {
            return match ($language) {
                'en' => 'Yes — '.$match->name.' is one of the registered student clubs.',
                'ru' => 'Да — «'.$match->name.'» есть среди зарегистрированных клубов.',
                default => 'Evet, '.$match->name.' kayıtlı öğrenci kulüplerinden biri.',
            };
        }

        /*
         * Saying "no" is only honest because this list is complete: clubs are
         * maintained in the admin panel, so a club with no row does not
         * exist. Listing the real ones turns a refusal into something the
         * student can act on.
         */
        $names = $clubs->pluck('name')->implode(', ');

        return match ($language) {
            'en' => 'No club by that name is registered. The registered student clubs are: '.$names.'.',
            'ru' => 'Клуба с таким названием нет. Зарегистрированные клубы: '.$names.'.',
            default => 'Bu adda kayıtlı bir kulüp yok. Kayıtlı öğrenci kulüpleri: '.$names.'.',
        };
    }

    private function sportAnswer(string $folded, string $language): ?string
    {
        $sports = Sport::query()->orderBy('name')->get(['name', 'facility']);
        if ($sports->isEmpty()) {
            return null;
        }

        $match = $this->bestMatch($sports, $folded);

        if ($match !== null) {
            return match ($language) {
                'en' => 'Yes — '.$match->name.' is on the recorded list; facility: '.$match->facility.'.',
                'ru' => 'Да — «'.$match->name.'» есть в списке; место: '.$match->facility.'.',
                default => 'Evet, '.$match->name.' kayıtlı listede; tesis: '.$match->facility.'.',
            };
        }

        $names = $sports->pluck('name')->implode(', ');

        return match ($language) {
            'en' => 'That is not on the recorded list of sports. Recorded: '.$names.'.',
            'ru' => 'Этого нет в списке видов спорта. В списке: '.$names.'.',
            default => 'Bu, kayıtlı spor listesinde yok. Kayıtlı olanlar: '.$names.'.',
        };
    }

    /**
     * The row whose name the question matches BEST, not the first that
     * matches at all.
     *
     * Measured: "E-Spor ve Oyun Tasarımı Kulübü var mı" was confirmed as
     * "Blender Modelleme ve Tasarım Kulübü". Both names contain "tasarım",
     * and taking the first match meant confirming the wrong club by name —
     * which reads as an answer rather than as a near miss.
     *
     * Scored by how much of the name the question actually accounts for, so
     * a row matching three of its words beats one matching a single shared
     * word.
     *
     * @param  Collection<int, object>  $rows
     */
    private function bestMatch($rows, string $folded): ?object
    {
        $best = null;
        $bestScore = 0;

        foreach ($rows as $row) {
            $score = 0;
            foreach ($this->significantWords(TextFold::fold((string) $row->name)) as $word) {
                if (str_contains($folded, $word)) {
                    // Longer words are stronger evidence than short ones.
                    $score += mb_strlen($word);
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $row;
            }
        }

        return $best;
    }

    /**
     * Whether ARUCAD teaches a subject, checked against the institution
     * profile rather than against whatever page mentions the word.
     *
     * Measured: "hukuk okuyabilir miyim" was answered "Evet, hukuk
     * okuyabilirsin" because a COURSE called "İletişim Hukuku ve Etik"
     * exists. Someone could enrol expecting a law degree.
     *
     * The profile is the right list to check because it is the university's
     * own statement of what it teaches, it is maintained in the admin panel,
     * and it names every faculty and undergraduate programme. A subject that
     * is not in it is not taught here.
     */
    private function programmeAnswer(string $folded, string $language): ?string
    {
        $profile = TextFold::fold(app(InstitutionProfile::class)->text());
        if ($profile === '') {
            return null;
        }

        /*
         * The subject asked about: the question's own words, minus the ones
         * that every such question contains. If nothing distinctive is left
         * ("bölüm var mı"), this is a listing question and not ours.
         */
        $subjects = $this->subjectWords($folded);
        if ($subjects === []) {
            return null;
        }

        foreach ($subjects as $subject) {
            if (str_contains($profile, $subject)) {
                // Named in the profile: let the normal pipeline answer
                // properly, with the programme page behind it.
                return null;
            }
        }

        return match ($language) {
            'en' => 'ARUCAD does not teach that subject. It is a thematic university for '
                .'art, design and communication, with four faculties: Arts, Design, '
                .'Communication, and Music and Performing Arts.',
            'ru' => 'ARUCAD не обучает этой специальности. Это тематический университет '
                .'искусства, дизайна и коммуникации с четырьмя факультетами: искусства, '
                .'дизайна, коммуникации, музыки и сценических искусств.',
            default => 'ARUCAD bu alanda eğitim vermiyor. ARUCAD sanat, tasarım ve iletişim '
                .'odaklı tematik bir üniversite; dört fakültesi var: Sanat, Tasarım, '
                .'İletişim, Müzik ve Sahne Sanatları.',
        };
    }

    /**
     * The distinctive words of the question — what is being asked about,
     * with the scaffolding of the question itself removed.
     *
     * @return list<string>
     */
    private function subjectWords(string $folded): array
    {
        $scaffold = array_merge(
            self::PROGRAMME_WORDS,
            ['var', 'mi', 'midir', 'arucad', 'universite', 'universitesi', 'burada',
                'there', 'have', 'does', 'study', 'can', 'the', 'here', 'lisans',
                'есть', 'ли', 'здесь',
                // Short Turkish function words, now that three-letter words
                // count as subjects.
                'ile', 'icin', 'ver', 'bir', 'siz', 'ben', 'bana', 'miyim',
                'mu', 'mü', 'ne', 've', 'veya', 'ise', 'daha', 'gibi'],
        );

        $out = [];
        foreach (preg_split('/[^\p{L}\p{N}]+/u', $folded, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            // Three, not four: "tıp" is a whole subject, and a four-character
            // floor dropped it entirely, leaving nothing to check and the
            // question unanswered.
            if (mb_strlen($word) >= 3 && ! in_array($word, $scaffold, true)) {
                $out[] = $word;
            }
        }

        return array_values(array_unique(CampusVocabulary::expand($out)));
    }

    /**
     * The words in an entity's name that could identify it.
     *
     * "Kulübü", "ARUCAD" and "Takımı" appear in most rows, so matching on
     * them would make every question match the first club in the table.
     * Short words are dropped for the same reason.
     *
     * @return list<string>
     */
    private function significantWords(string $foldedName): array
    {
        $generic = ['kulubu', 'kulup', 'club', 'arucad', 'takimi', 'takim', 've', 'ile', 'and', 'erkek', 'kadin'];

        $out = [];
        $words = preg_split('/[^\p{L}\p{N}]+/u', $foldedName, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach ($words as $word) {
            // Three, not four: "Hip-Hop Kulübü" splits into "hip" and "hop"
            // and a four-character floor silently made that club unmatchable,
            // so the assistant said it did not exist.
            if (mb_strlen($word) >= 3 && ! in_array($word, $generic, true)) {
                $out[] = $word;
            }
        }

        // The name with its separators removed, so "hip-hop" is also found
        // when the student writes "hiphop".
        $joined = implode('', array_filter($words, static fn (string $w) => ! in_array($w, $generic, true)));
        if (mb_strlen($joined) >= 5) {
            $out[] = $joined;
        }

        /*
         * Cross-language, because the club list is Turkish and the question
         * may not be: "is there a dance club" must find "Dans Kulübü". The
         * same expansion the knowledge base uses for retrieval, so the two
         * cannot disagree about which words mean the same thing.
         */
        return array_values(array_unique(CampusVocabulary::expand($out)));
    }
}
