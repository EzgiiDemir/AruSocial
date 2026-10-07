<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Grafik Tasarım İngilizce mi?" asks for the language of instruction
 * without naming it. Adds yes/no phrasings to the existing concept, in the
 * three supported languages, so the planner recognises a programme-language
 * clause alongside other tasks.
 *
 * Data only and non-destructive: phrases are appended when missing, so an
 * admin's edits to the concept survive; down() removes only these.
 * Deliberately NOT "in english" / "на английском" alone — those also mean
 * "answer me in English".
 */
return new class extends Migration
{
    private const PHRASES = [
        'ingilizce mi', 'turkce mi', 'ingilizce egitim', 'turkce egitim', 'ingilizce mi okutuluyor',
        'taught in', 'english taught', 'english or turkish', 'turkish or english',
        'обучение на английском', 'обучение на турецком', 'преподают на',
    ];

    public function up(): void
    {
        $this->update(fn (array $phrases) => array_values(array_unique([...$phrases, ...self::PHRASES])));
    }

    public function down(): void
    {
        $this->update(fn (array $phrases) => array_values(array_diff($phrases, self::PHRASES)));
    }

    private function update(callable $change): void
    {
        $row = DB::table('ai_query_concepts')->where('concept', 'language_of_instruction')->first();
        if ($row === null) {
            return;
        }
        $phrases = (array) json_decode((string) $row->phrases, true);
        DB::table('ai_query_concepts')->where('id', $row->id)->update([
            'phrases' => json_encode($change($phrases), JSON_UNESCAPED_UNICODE),
            'updated_at' => now(),
        ]);
    }
};
