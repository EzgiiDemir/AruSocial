<?php

namespace App\Services;

use App\Models\ApplicationQuestion;
use Illuminate\Support\Collection;

// Reads the admin-managed question schema behind both stages of the apply
// flow. Never hardcode a category's questions elsewhere — this is the
// only place that resolves "which questions does target type X ask at
// stage Y."
class ApplicationQuestionService
{
    /** @return Collection<int, ApplicationQuestion> */
    public static function forStage(string $targetType, string $stage): Collection
    {
        return ApplicationQuestion::where('target_type', $targetType)
            ->where('stage', $stage)
            ->where('active', true)
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Returns the labels of required questions missing a non-empty answer
     * in `$answers` (keyed by question id). Empty array means the answers
     * satisfy every required question for this stage.
     *
     * @param  array<string, mixed>  $answers
     * @return list<string>
     */
    public static function missingRequired(string $targetType, string $stage, array $answers): array
    {
        $missing = [];
        foreach (self::forStage($targetType, $stage) as $question) {
            if (! $question->required) {
                continue;
            }
            $value = $answers[$question->id] ?? null;
            $isEmpty = $value === null
                || $value === ''
                || (is_array($value) && count($value) === 0);
            if ($isEmpty) {
                $missing[] = $question->label;
            }
        }

        return $missing;
    }
}
