<?php

namespace App\Filament\Resources\AiEvaluationRuns\Pages;

use App\Filament\Pages\AskPlayground;
use App\Filament\Resources\AiEvaluationRuns\AiEvaluationRunResource;
use App\Models\AiEvaluationResult;
use App\Models\AiEvaluationRun;
use App\Services\Ai\Evaluation\AssertionEvaluator;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Collection;

/**
 * One run: totals, failures grouped by pipeline stage (earliest first), and
 * each failure's expected vs actual with the evidence behind it.
 */
class ViewAiEvaluationRun extends ViewRecord
{
    protected static string $resource = AiEvaluationRunResource::class;

    protected string $view = 'filament.pages.ai-evaluation-run';

    /** @return array<string, Collection<int, AiEvaluationResult>> stage => failed results, in pipeline order */
    public function failuresByStage(): array
    {
        /** @var AiEvaluationRun $run */
        $run = $this->getRecord();
        $failed = $run->results()->where('status', AiEvaluationResult::STATUS_FAILED)->orderBy('id')->get()
            ->groupBy(fn ($r) => $r->failure_stage ?? 'expected_assertion');
        $out = [];
        foreach (AssertionEvaluator::STAGES as $stage) {
            if ($failed->has($stage)) {
                $out[$stage] = $failed[$stage];
            }
        }

        return $out;
    }

    /** @return Collection<int, AiEvaluationResult> */
    public function skipped(): Collection
    {
        return $this->getRecord()->results()->where('status', AiEvaluationResult::STATUS_SKIPPED)->get();
    }

    /** Re-run the question in the Search Playground with the same context. */
    public function playgroundUrl(AiEvaluationResult $result): string
    {
        $case = $result->evaluationCase;
        $previous = collect((array) ($case?->previous_turns ?? []))->pluck('content')->implode("\n");

        return AskPlayground::getUrl(array_filter([
            'question' => $result->question,
            'previous' => $previous,
            'mode' => $result->mode === 'full' ? 'full' : 'retrieval',
        ]));
    }
}
