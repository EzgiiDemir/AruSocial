<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Sport;
use App\Services\Ai\EntityExistence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Is there an X?" — answered from the list, never guessed.
 *
 * Every case here comes from a real answer measured across 194 questions.
 * The pattern was always the same: retrieval found a page containing the
 * word, and the model read the presence of the word as the existence of the
 * thing.
 */
class EntityExistenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'Tiyatro Kulübü', 'Dans Kulübü', 'Hip-Hop Kulübü', 'Tırmanış Kulübü',
            // Two names sharing a word, for the best-match case below.
            'Blender Modelleme ve Tasarım Kulübü', 'E-Spor ve Oyun Tasarımı Kulübü',
        ] as $i => $name) {
            Club::create([
                'id' => 'club-'.$i,
                'name' => $name,
                'category' => 'Öğrenci Kulübü',
            ]);
        }
    }

    private function service(): EntityExistence
    {
        return app(EntityExistence::class);
    }

    /**
     * The measured failure: "Evet, ARUCAD'de satranç kulübü vardır", with
     * invented activities, for a club that has no row.
     */
    public function test_a_club_that_does_not_exist_gets_a_plain_no(): void
    {
        $answer = $this->service()->answer('satranç kulübü var mı', 'tr');

        $this->assertNotNull($answer);
        $this->assertStringNotContainsString('Evet', $answer);
        // A bare "no" is not useful; the real list is.
        $this->assertStringContainsString('Tiyatro Kulübü', $answer);
    }

    /** A club that does exist is confirmed by name, not described vaguely. */
    public function test_a_club_that_exists_is_confirmed(): void
    {
        $answer = $this->service()->answer('tiyatro kulübü var mı', 'tr');

        $this->assertNotNull($answer);
        $this->assertStringStartsWith('Evet', $answer);
        $this->assertStringContainsString('Tiyatro Kulübü', $answer);
    }

    /**
     * The club list is Turkish and the question may not be.
     *
     * Without cross-language expansion this answered "no club by that name"
     * for Dans Kulübü — a wrong "no", which is a worse failure than the
     * invention it replaced.
     */
    public function test_an_english_question_finds_a_turkish_club(): void
    {
        $answer = $this->service()->answer('is there a dance club', 'en');

        $this->assertNotNull($answer);
        $this->assertStringStartsWith('Yes', $answer);
        $this->assertStringContainsString('Dans Kulübü', $answer);
    }

    /**
     * A hyphenated name splits into words too short to match on their own,
     * which silently made Hip-Hop Kulübü unfindable.
     */
    public function test_a_hyphenated_club_name_is_found(): void
    {
        $answer = $this->service()->answer('hip-hop kulübü var mı', 'tr');

        $this->assertNotNull($answer);
        $this->assertStringStartsWith('Evet', $answer);
    }

    /** Sports come from their own list, with the facility. */
    public function test_sports_are_answered_from_the_recorded_list(): void
    {
        Sport::create(['id' => 'sport-1', 'name' => 'ARUCAD Futsal Takımı', 'facility' => 'Spor Salonu']);

        $yes = $this->service()->answer('futsal takımı var mı', 'tr');
        $this->assertNotNull($yes);
        $this->assertStringStartsWith('Evet', $yes);
        $this->assertStringContainsString('Spor Salonu', $yes);

        $no = $this->service()->answer('buz hokeyi takımı var mı', 'tr');
        $this->assertNotNull($no);
        $this->assertStringNotContainsString('Evet', $no);
    }

    /**
     * "hukuk okuyabilir miyim" was answered "Evet, hukuk okuyabilirsin",
     * because a COURSE called "İletişim Hukuku ve Etik" exists. Someone
     * could enrol expecting a law degree.
     *
     * Checked against the institution profile, which is the university's own
     * statement of what it teaches and is maintained in the admin panel.
     */
    public function test_a_subject_not_taught_here_is_declined(): void
    {
        foreach ([
            'hukuk okuyabilir miyim',
            'tıp fakültesi var mı',
            'mühendislik bölümü var mı',
            'psikoloji okuyabilir miyim',
        ] as $question) {
            $answer = $this->service()->answer($question, 'tr');
            $this->assertNotNull($answer, "should decline: {$question}");
            $this->assertStringNotContainsString('Evet', $answer);
        }
    }

    /**
     * A subject that IS taught falls through, so the programme page answers
     * properly instead of this returning a bare confirmation.
     */
    public function test_a_subject_that_is_taught_falls_through(): void
    {
        foreach ([
            'mimarlık bölümü var mı',
            'seramik bölümü var mı',
            'fotoğraf okuyabilir miyim',
            'arkeoloji bölümü var mı',
        ] as $question) {
            $this->assertNull(
                $this->service()->answer($question, 'tr'),
                "should fall through: {$question}",
            );
        }
    }

    /**
     * The best match, not the first.
     *
     * Measured: "E-Spor ve Oyun Tasarımı Kulübü var mı" was confirmed as
     * "Blender Modelleme ve Tasarım Kulübü" — both contain "tasarım", and
     * naming the wrong club reads as an answer rather than a near miss.
     */
    public function test_the_closest_club_wins_when_names_share_a_word(): void
    {
        $answer = $this->service()->answer('E-Spor ve Oyun Tasarımı Kulübü var mı', 'tr');

        $this->assertNotNull($answer);
        $this->assertStringContainsString('E-Spor ve Oyun Tasarımı Kulübü', $answer);
        $this->assertStringNotContainsString('Blender', $answer);
    }

    /**
     * Only existence questions, and only about categories we hold a complete
     * list for. Everything else must fall through, because answering "no"
     * from a partial list is its own confident wrong.
     */
    public function test_other_questions_are_left_alone(): void
    {
        foreach ([
            'kulüpler neler',                    // a listing, not existence
            'tiyatro kulübüne nasıl katılırım',  // a procedure
            'kütüphane var mı',                  // no complete list here
            'burs var mı',                       // nor here
            'rektör kim',
        ] as $question) {
            $this->assertNull(
                $this->service()->answer($question, 'tr'),
                "should fall through: {$question}",
            );
        }
    }
}
