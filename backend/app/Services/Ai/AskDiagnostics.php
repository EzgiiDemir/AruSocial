<?php

namespace App\Services\Ai;

use App\Http\Controllers\Api\AiController;
use App\Models\User;
use App\Services\Ai\Facts\SupportedFactsRollout;
use App\Services\Ai\Planning\TaskOrchestrator;
use App\Services\AskConversationService;
use App\Services\CampusAskFallback;
use App\Services\Web\WebResearchService;
use Illuminate\Http\Request;

/**
 * Runs one Ask question for staff diagnostics and returns its full trace.
 *
 * Two modes, because most bad answers are retrieval bugs mistaken for model
 * bugs:
 *
 *  - retrieval: every stage up to the model — follow-up rewriting, the
 *    operational and direct paths, routing, entities, database rows,
 *    knowledge ranking with score breakdown, prompt budget. No model call
 *    and no web search, so it is free and deterministic.
 *  - full: the real POST /api/ai/query code path, run as the signed-in
 *    staff member, with the same trace switched to verbose. The
 *    conversation it would add to their Ask history is removed again.
 *
 * Used by the Search Playground page and `php artisan ask:diagnose`.
 */
final class AskDiagnostics
{
    public const MODE_RETRIEVAL = 'retrieval';

    public const MODE_FULL = 'full';

    /**
     * @param  list<array{role: string, content: string}>  $history  Earlier turns, oldest first.
     * @return array<string, mixed>
     */
    public function run(string $question, string $mode = self::MODE_RETRIEVAL, array $history = [], bool $allowWeb = true, ?User $as = null): array
    {
        // A fresh trace per run, verbose: a CLI process or a Livewire request
        // may diagnose several questions in a row.
        $trace = new AskTrace;
        $trace->setVerbose();
        app()->instance(AskTrace::class, $trace);
        // Likewise the per-request rollout state, or one case's path and
        // signals would leak into the next.
        // Diagnostic runs (Playground, evaluation, readiness probes) are
        // traced but never counted in the rollout aggregates: those describe
        // real traffic only.
        $rollout = new SupportedFactsRollout;
        $rollout->markDiagnostic();
        app()->instance(SupportedFactsRollout::class, $rollout);

        $messages = [...$history, ['role' => 'user', 'content' => $question]];

        $result = $mode === self::MODE_FULL
            ? $this->full($messages, $allowWeb, $as)
            : $this->retrievalOnly($question, $messages, $trace);

        return [
            'trace_id' => $trace->id,
            'mode' => $mode,
            'question' => $question,
            'result' => $result,
            'stages' => $trace->stages(),
        ];
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @return array<string, mixed>
     */
    private function retrievalOnly(string $question, array $messages, AskTrace $trace): array
    {
        $retrievalQuery = FollowUpQuery::resolve($messages, $question);
        $trace->record('follow_up', [
            'prompt' => $question,
            'retrieval_query' => $retrievalQuery,
            'rewritten' => $retrievalQuery !== $question,
        ]);

        // Phase 3A planning, exactly as the API runs it.
        $planning = app(TaskOrchestrator::class)->run($question, array_slice($messages, 0, -1), null);

        $operations = app(AskOperations::class)->resolve(
            $question, null, 'walking', $retrievalQuery === $question ? null : $retrievalQuery,
        );
        $handoff = [];
        if ($planning->mayReplaceOperational($operations)) {
            $operations['answer'] = null;
            $handoff[] = 'operational';
        }
        $trace->record('operational', [
            'answered' => $operations['answer'] !== null,
            'places' => count((array) $operations['places']),
            'events' => count((array) $operations['events']),
            'route' => $operations['route'] !== null && $operations['route'] !== [],
        ]);

        $isSingleTurn = count($messages) <= 1;
        // Anonymous on purpose: retrieval mode never reads anyone's data.
        $direct = $isSingleTurn ? app(DirectAnswer::class)->tryAnswer($question, null, now()) : null;
        if ($planning->planned() && $direct !== null) {
            $direct = null;
            $handoff[] = 'direct';
        }
        if ($planning->planned()) {
            $trace->record('planning_handoff', ['suppressed_fast_paths' => $handoff,
                'reason' => 'compositional question: a single-intent answer would cover only one task']);
        }
        $trace->record('direct', ['attempted' => $isSingleTurn, 'answered' => $direct !== null]);

        $built = app(AskPromptBuilder::class)->build($question, $retrievalQuery, null, $planning);

        $research = app(WebResearchService::class);
        $trace->record('web_research', [
            'available' => $research->isAvailable(),
            'triggered' => $research->shouldResearch($retrievalQuery, $built['sources']),
            'note' => 'retrieval mode reports whether web research would run; it never runs it',
        ]);

        // Which path would have answered, in the controller's order.
        $wouldServe = match (true) {
            $operations['answer'] !== null => 'operational',
            $direct !== null => 'direct',
            default => 'model',
        };

        return [
            'would_serve' => $wouldServe,
            'operational_answer' => $operations['answer'],
            'direct_answer' => $direct,
            'places' => $operations['places'],
            'events' => $operations['events'],
            'route' => $operations['route'],
            'warnings' => $operations['warnings'],
            'sources' => $built['sources'],
            'prompt_chars' => mb_strlen($built['prompt']),
            'prompt_tokens_estimated' => PromptBudget::estimate($built['prompt']),
        ];
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @return array<string, mixed>
     */
    private function full(array $messages, bool $allowWeb, ?User $as): array
    {
        $user = $as ?? auth()->user();
        if (! $user instanceof User) {
            return ['error' => 'Full mode runs as a signed-in user; none is available.'];
        }
        // The controller reads the user from the ambient request, whichever
        // guard (session in the panel, none on the CLI) is in use; pinned
        // for this call only and restored below.
        $ambient = request();
        $previousResolver = $ambient->getUserResolver();
        $ambient->setUserResolver(fn () => $user);

        $previousWeb = config('ai.web_research.enabled');
        if (! $allowWeb) {
            config(['ai.web_research.enabled' => false]);
        }

        try {
            $request = Request::create('/api/ai/query', 'POST', ['messages' => $messages]);
            $response = app(AiController::class)->query(
                $request,
                app(AskConversationService::class),
                app(CampusAskFallback::class),
            );
        } finally {
            config(['ai.web_research.enabled' => $previousWeb]);
            $ambient->setUserResolver($previousResolver);
        }

        $payload = $response->getData(true);
        $data = $payload['data'] ?? $payload;

        // A diagnostic run must not leave a conversation in the staff
        // member's own Ask history.
        if (is_string($data['conversationId'] ?? null)) {
            app(AskConversationService::class)->ownedBy($user, $data['conversationId'])?->delete();
            unset($data['conversationId']);
        }

        return $data;
    }
}
