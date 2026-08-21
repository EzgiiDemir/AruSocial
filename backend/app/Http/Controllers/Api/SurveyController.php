<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\Survey;
use App\Models\SurveyOption;
use App\Models\SurveyResponse;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

// Real popup survey/poll system — "Bahar Şenliği hangi tarihte olsun?",
// "Şebnem Ferah mı, Teoman mı?" style quick polls, per
// docs/EKSIKLER.md §8.
class SurveyController extends Controller
{
    use ApiResponds;

    private function toJson(Survey $s, ?int $meId = null): array
    {
        $totalVotes = $s->responses()->count();
        $myOptionIds = $meId
            ? $s->responses()->where('user_id', $meId)->pluck('option_id')->all()
            : [];

        return [
            'id' => $s->id,
            'question' => $s->question,
            'description' => $s->description,
            'startsAt' => $s->starts_at?->toIso8601String(),
            'endsAt' => $s->ends_at?->toIso8601String(),
            'targetAudience' => $s->target_audience,
            'multipleChoice' => $s->multiple_choice,
            'anonymous' => $s->anonymous,
            'showResults' => $s->show_results,
            'active' => $s->active,
            'totalVotes' => $totalVotes,
            'myOptionIds' => $myOptionIds,
            'options' => $s->options->map(fn ($o) => [
                'id' => $o->id,
                'label' => $o->label,
                'votes' => $o->responses()->count(),
                'percentage' => $totalVotes > 0 ? round($o->responses()->count() / $totalVotes * 100, 1) : 0,
            ]),
        ];
    }

    // Only currently-active surveys — this is what the student-facing
    // popup reads.
    public function activeIndex(): JsonResponse
    {
        $me = $this->currentUser();
        $now = now();
        $surveys = Survey::with('options')
            ->where('active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
            ->get();

        return $this->ok($surveys->map(fn ($s) => $this->toJson($s, $me->id)));
    }

    // Every survey (any status) — admin management list.
    public function adminIndex(): JsonResponse
    {
        $surveys = Survey::with('options')->orderByDesc('created_at')->get();

        return $this->ok($surveys->map(fn ($s) => $this->toJson($s)));
    }

    public function upsert(Request $request): JsonResponse
    {
        $id = $request->input('id') ?: 'survey-'.Str::uuid();
        $question = $request->input('question');
        $options = $request->input('options', []);
        if (! $question || count($options) < 2) {
            return $this->fail(400, 'VALIDATION', 'question and at least 2 options are required.');
        }

        $isNew = ! Survey::where('id', $id)->exists();
        $survey = Survey::updateOrCreate(['id' => $id], [
            'question' => $question,
            'description' => $request->input('description'),
            'starts_at' => $request->input('startsAt'),
            'ends_at' => $request->input('endsAt'),
            'target_audience' => $request->input('targetAudience', 'Tümü'),
            'multiple_choice' => (bool) $request->input('multipleChoice', false),
            'anonymous' => (bool) $request->input('anonymous', true),
            'show_results' => (bool) $request->input('showResults', true),
            'active' => (bool) $request->input('active', true),
            'created_by' => $this->currentUser()->name,
            'created_at' => $isNew ? now() : Survey::find($id)->created_at,
        ]);

        // Options are replaced wholesale on edit — simplest correct model
        // for a poll that shouldn't be restructured mid-vote anyway.
        if ($isNew || $request->has('options')) {
            SurveyOption::where('survey_id', $id)->delete();
            foreach ($options as $i => $label) {
                SurveyOption::create([
                    'id' => 'opt-'.Str::uuid(),
                    'survey_id' => $id,
                    'label' => $label,
                    'sort_order' => $i,
                ]);
            }
        }
        AuditLogger::log($this->currentUser()->name, $isNew ? 'create' : 'update', 'survey', $question);

        return $this->ok($this->toJson($survey->fresh('options')));
    }

    public function vote(Request $request, string $id): JsonResponse
    {
        $me = $this->currentUser();
        $survey = Survey::find($id);
        if (! $survey) return $this->fail(404, 'SURVEY_NOT_FOUND', 'Survey not found.');
        if (! $survey->active) return $this->fail(400, 'SURVEY_INACTIVE', 'This survey is no longer active.');

        $optionIds = (array) $request->input('optionIds', []);
        if (empty($optionIds)) return $this->fail(400, 'VALIDATION', 'optionIds is required.');
        if (! $survey->multiple_choice && count($optionIds) > 1) {
            return $this->fail(400, 'VALIDATION', 'This survey only accepts a single choice.');
        }

        SurveyResponse::where('survey_id', $id)->where('user_id', $me->id)->delete();
        foreach ($optionIds as $optionId) {
            SurveyResponse::create([
                'survey_id' => $id,
                'option_id' => $optionId,
                'user_id' => $me->id,
                'created_at' => now(),
            ]);
        }

        return $this->ok($this->toJson($survey->fresh('options'), $me->id));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $survey = Survey::find($id);
        if ($survey) {
            AuditLogger::log($this->currentUser()->name, 'delete', 'survey', $survey->question);
            $survey->delete();
        }

        return $this->ok(['deleted' => true]);
    }
}
