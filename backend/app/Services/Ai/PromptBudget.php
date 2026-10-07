<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Log;

/**
 * Keeps the prompt inside the model's context window, deterministically, and
 * decides what goes when something has to.
 *
 * WHY THE RUNTIME CANNOT BE TRUSTED TO DO THIS
 *
 * Every inference runtime truncates an over-long prompt, and they all do it
 * the same way: they keep the most recent tokens and drop the oldest. That is
 * the correct default for a chat transcript and exactly wrong for this prompt,
 * because ours begins with the rules. Measured against Ollama's default 4096
 * window: a 6,229-token prompt arrived as 2,050 tokens, and what had been
 * discarded was the language rule, the "never invent a number" rule, the
 * source-priority order and the prompt-injection fence. The model answered
 * fluently and without a single guardrail, and nothing anywhere reported an
 * error — which is what makes silent truncation worse than a hard failure.
 *
 * So the application decides. If something has to be dropped it is chosen
 * here, in priority order, and it is counted.
 *
 * THE ORDER, LEAST VALUABLE FIRST
 *
 *   1. Retrieved sources   — whole chunks from the end. Fewer sources means a
 *                            thinner answer; it does not mean an unsafe one.
 *   2. Conversation history — oldest turns first, newest always kept.
 *   3. Nothing else.        The rules, the metadata, the personal block and
 *                           the final reinforcement are never touched. If
 *                           they alone do not fit, that is a configuration
 *                           error and it is logged as one rather than being
 *                           papered over.
 *
 * ON COUNTING TOKENS WITHOUT A TOKENISER
 *
 * There is no Qwen tokeniser in PHP and shelling out to one per request would
 * cost more than it saves. The ratio is measured instead, against this actual
 * prompt and this actual model: 16,333 characters of Turkish came back as
 * 6,229 prompt tokens, so 2.62 characters per token. CHARS_PER_TOKEN is set
 * below that, at 2.4, because the estimate must err towards thinking the
 * prompt is BIGGER than it is. Over-estimating costs a source we did not have
 * to drop; under-estimating costs the rules, silently.
 */
final class PromptBudget
{
    /**
     * Deliberately pessimistic. Turkish and Russian tokenise at roughly 2.6
     * characters per token on this model, English nearer 4; 2.4 keeps the
     * estimate on the safe side of the worst case rather than the average.
     */
    private const CHARS_PER_TOKEN = 2.4;

    /**
     * A rough token count for a string.
     *
     * Rough is the honest word: this is a budget guard, not an accounting
     * system. It only ever has to be right enough to keep us off the ceiling.
     */
    public static function estimate(string $text): int
    {
        if ($text === '') {
            return 0;
        }

        return (int) ceil(mb_strlen($text) / self::CHARS_PER_TOKEN);
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     */
    public static function estimateMessages(array $messages): int
    {
        $total = 0;
        foreach ($messages as $message) {
            // A few tokens of per-message chat-template overhead
            // (<|im_start|>role ... <|im_end|>), which is small but real and
            // adds up over a dozen turns.
            $total += self::estimate((string) ($message['content'] ?? '')) + 4;
        }

        return $total;
    }

    /** How many prompt tokens this deployment may use. */
    public static function maxPromptTokens(): int
    {
        return max(1024, (int) config('ai.context.max_prompt_tokens', 11000));
    }

    /**
     * Trim the retrieved-source block to whatever room is left.
     *
     * Cuts on a paragraph boundary rather than mid-sentence: half a sentence
     * of a fee table is not evidence, and the grounding check would then be
     * comparing an answer against a source that stops mid-number.
     */
    public static function fitContext(string $context, int $availableTokens): string
    {
        if ($availableTokens <= 0) {
            return '';
        }
        if (self::estimate($context) <= $availableTokens) {
            return $context;
        }

        $limit = (int) floor($availableTokens * self::CHARS_PER_TOKEN);
        $cut = mb_substr($context, 0, $limit);

        // Prefer the last blank line, then the last sentence end.
        foreach (["\n\n", "\n", '. '] as $boundary) {
            $at = mb_strrpos($cut, $boundary);
            if ($at !== false && $at > $limit * 0.5) {
                return mb_substr($cut, 0, $at);
            }
        }

        return $cut;
    }

    /**
     * Drop the oldest conversation turns until the whole payload fits.
     *
     * The system message and the newest user message are never candidates:
     * the first carries every rule, and the second is the question. If those
     * two alone exceed the budget the payload is returned unchanged and the
     * problem is logged — there is nothing left this can safely remove, and
     * pretending otherwise would just move the truncation back to the runtime
     * where it silently eats the rules.
     *
     * @param  list<array{role: string, content: string}>  $payload
     * @return array{0: list<array{role: string, content: string}>, 1: bool}
     */
    public static function fitPayload(array $payload, ?int $maxTokens = null): array
    {
        $max = $maxTokens ?? self::maxPromptTokens();
        if (self::estimateMessages($payload) <= $max || count($payload) <= 2) {
            return [$payload, false];
        }

        $system = array_shift($payload);
        $newest = array_pop($payload);
        $compacted = false;

        while ($payload !== []
            && self::estimateMessages([$system, ...$payload, $newest]) > $max) {
            array_shift($payload);   // oldest first
            $compacted = true;
        }

        $result = [$system, ...$payload, $newest];

        if (self::estimateMessages($result) > $max) {
            // Everything droppable is gone and it still does not fit.
            Log::warning('ai.context.over_budget', [
                'estimated_tokens' => self::estimateMessages($result),
                'max_prompt_tokens' => $max,
                'note' => 'System prompt plus the current question exceed the budget. '
                    .'Lower ai.context_budget_chars or raise the model context window; '
                    .'the runtime will now truncate, and it truncates the rules first.',
            ]);
        }

        if ($compacted) {
            app(AiTelemetry::class)->bump(AiTelemetry::CONTEXT_COMPACTIONS);
        }

        return [$result, $compacted];
    }
}
