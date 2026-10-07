<?php

namespace Tests\Feature;

use App\Support\QueryLanguage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Language routing, which decides what language a student is answered in.
 *
 * The regression this guards: detection used to score a question against a
 * list of function words, and a short noun phrase contains none. "library
 * opening hours", "academic calendar" and "architecture programme" all scored
 * zero for both languages, returned null, and AskPromptBuilder then added no
 * language directive at all — so a Turkish system prompt answered an English
 * question in Turkish. Three of the four language failures in `ask:eval` were
 * this, and none of them was the model's fault.
 *
 * Short queries are therefore the bulk of what is asserted here. They are also
 * what students actually type.
 */
class QueryLanguageDetectionTest extends TestCase
{
    /** @return list<array{0: string, 1: string}> */
    public static function englishQueries(): array
    {
        return [
            // Short noun phrases — the case that was broken.
            ['library opening hours', 'en'],
            ['academic calendar', 'en'],
            ['architecture programme', 'en'],
            ['tuition fees', 'en'],
            ['student affairs office', 'en'],
            ['scholarship application', 'en'],
            ['dormitory prices', 'en'],
            ['campus map', 'en'],
            ['exam schedule', 'en'],
            ['shuttle bus times', 'en'],
            // Ordinary sentences.
            ['What scholarships are available?', 'en'],
            ['Which department suits me best?', 'en'],
            ['How do I apply for the preparatory year?', 'en'],
            ['I feel nervous about starting university next month. Any advice?', 'en'],
        ];
    }

    /** @return list<array{0: string, 1: string}> */
    public static function turkishQueries(): array
    {
        return [
            // With diacritics.
            ['kütüphane saatleri', 'tr'],
            ['akademik takvim', 'tr'],
            ['mimarlık bölümü', 'tr'],
            ['burs imkanları nelerdir', 'tr'],
            ['bugün hangi derslerim var', 'tr'],
            // Latin-script Turkish typed without diacritics, which is how a
            // great many students actually type on a phone.
            ['kutuphane saatleri', 'tr'],
            ['ogrenci isleri nerede', 'tr'],
            ['burs var mi', 'tr'],
            ['yurt ucretleri ne kadar', 'tr'],
            ['akademik takvim ne zaman aciklanir', 'tr'],
            // Single words and greetings.
            ['Merhaba', 'tr'],
            ['ARUCADda hangi bölüm bana uygun olur', 'tr'],
        ];
    }

    /** @return list<array{0: string, 1: string}> */
    public static function russianQueries(): array
    {
        return [
            ['Какие есть стипендии?', 'ru'],
            ['библиотека часы работы', 'ru'],
            ['академический календарь', 'ru'],
            ['Какие у меня сегодня занятия?', 'ru'],
            ['Какие программы есть в ARUCAD?', 'ru'],
            ['общежитие стоимость', 'ru'],
            ['расписание автобусов', 'ru'],
        ];
    }

    #[DataProvider('englishQueries')]
    #[DataProvider('turkishQueries')]
    #[DataProvider('russianQueries')]
    public function test_a_query_resolves_to_its_language(string $query, string $expected): void
    {
        $this->assertSame(
            $expected,
            QueryLanguage::detect($query),
            "Wrong language for: {$query}",
        );
    }

    /**
     * A question with an ARUCAD brand name in it is still the student's
     * language, not English by association. Proper nouns are not evidence.
     */
    public function test_a_brand_name_does_not_pull_the_language_to_english(): void
    {
        $this->assertSame('tr', QueryLanguage::detect('ARUCAD kampüsünde kütüphane nerede'));
        $this->assertSame('ru', QueryLanguage::detect('Где в ARUCAD библиотека?'));
    }

    /**
     * Mixed input resolves to whichever language carries the question. Cyrillic
     * is decisive because no other supported language uses it, so a Russian
     * question with an English noun in it is still Russian.
     */
    public function test_mixed_language_input_resolves_to_the_dominant_language(): void
    {
        $this->assertSame('ru', QueryLanguage::detect('Где находится library?'));
        $this->assertSame('tr', QueryLanguage::detect('campus içinde kütüphane nerede acaba'));
    }

    /**
     * Turkish agglutination is a signal in its own right. Neither of these
     * words is in any list, and both are unmistakably Turkish.
     */
    public function test_turkish_morphology_is_recognised_without_a_dictionary(): void
    {
        $this->assertSame('tr', QueryLanguage::detect('fotoğrafçılık atölyelerinde'));
        $this->assertSame('tr', QueryLanguage::detect('seramik stüdyolarının kullanımı'));
    }

    public function test_empty_input_is_unknown_rather_than_guessed(): void
    {
        $this->assertNull(QueryLanguage::detect(''));
        $this->assertNull(QueryLanguage::detect('   '));
        $this->assertNull(QueryLanguage::detect('123 456'));
    }

    public function test_labels_exist_for_every_supported_language(): void
    {
        $this->assertSame('Türkçe', QueryLanguage::label('tr'));
        $this->assertSame('English', QueryLanguage::label('en'));
        $this->assertSame('Русский', QueryLanguage::label('ru'));
        $this->assertNull(QueryLanguage::label(null));
    }
}
