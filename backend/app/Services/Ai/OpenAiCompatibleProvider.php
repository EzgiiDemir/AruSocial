<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Shared implementation for any OpenAI-compatible /chat/completions backend.
 * Groq and a local Ollama/vLLM server both extend this and differ only in the
 * config array they are constructed with.
 */
abstract class OpenAiCompatibleProvider implements AiProvider
{
    /**
     * @param  array{base_url?: string, api_key?: string, model?: string, verify_ssl?: bool, timeout?: int}  $config
     */
    public function __construct(protected array $config) {}

    abstract public function key(): string;

    abstract public function label(): string;

    abstract public function isSelfHosted(): bool;

    protected function baseUrl(): string
    {
        return rtrim((string) ($this->config['base_url'] ?? ''), '/');
    }

    protected function model(): string
    {
        return (string) ($this->config['model'] ?? '');
    }

    protected function verifySsl(): bool
    {
        return (bool) ($this->config['verify_ssl'] ?? true);
    }

    protected function timeout(): int
    {
        return (int) ($this->config['timeout'] ?? 30);
    }

    /**
     * Generation settings, per provider, falling back to the shared
     * defaults. A local 9B and a hosted 20B do not have to be tuned
     * together, and neither is hard-coded.
     */
    protected function temperature(): float
    {
        $own = $this->config['temperature'] ?? null;

        return $own === null || $own === ''
            ? (float) config('ai.temperature', 0.2)
            : (float) $own;
    }

    protected function maxTokens(): int
    {
        $own = $this->config['max_tokens'] ?? null;

        return $own === null || $own === ''
            ? (int) config('ai.max_tokens', 800)
            : (int) $own;
    }

    /** A local provider needs only a base URL; a hosted one also needs a key. */
    public function isConfigured(): bool
    {
        if ($this->baseUrl() === '') {
            return false;
        }

        return $this->isSelfHosted() || (string) ($this->config['api_key'] ?? '') !== '';
    }

    public function complete(array $messages): ?string
    {
        return $this->attempt($messages)->text;
    }

    /**
     * One classified completion attempt. Never throws: a provider failure is
     * returned as a typed status so the router can retry/fall back and the
     * panel can show why (rate-limited vs quota vs outage vs timeout).
     */
    public function attempt(array $messages): AiCompletion
    {
        if (! $this->isConfigured()) {
            return AiCompletion::fail(AiCompletion::UNCONFIGURED);
        }

        $startedAt = microtime(true);

        try {
            $request = Http::timeout($this->timeout())
                ->withOptions(['verify' => $this->verifySsl()]);
            if ((string) ($this->config['api_key'] ?? '') !== '') {
                $request = $request->withToken((string) $this->config['api_key']);
            }

            $payload = [
                'model' => $this->model(),
                'messages' => array_values(array_map(
                    fn ($m) => [
                        'role' => in_array($m['role'] ?? 'user', ['system', 'assistant', 'user'], true)
                            ? $m['role'] : 'user',
                        'content' => (string) ($m['content'] ?? ''),
                    ],
                    $messages,
                )),
                'temperature' => $this->temperature(),
                'max_tokens' => $this->maxTokens(),
            ];
            // Reasoning-model controls (Groq gpt-oss/qwen): keep the chain of
            // thought out of the answer. Only sent when configured, so a plain
            // OpenAI-compatible endpoint that does not understand them is
            // unaffected.
            if (($this->config['reasoning_format'] ?? '') !== '') {
                $payload['reasoning_format'] = (string) $this->config['reasoning_format'];
            }
            if (($this->config['reasoning_effort'] ?? '') !== '') {
                $payload['reasoning_effort'] = (string) $this->config['reasoning_effort'];
            }

            $response = $request->post($this->baseUrl().'/chat/completions', $payload);

            if ($response->status() === 401) {
                return AiCompletion::fail(AiCompletion::UNAUTHORIZED, 401);
            }
            if ($response->status() === 429) {
                // Groq returns 429 for both burst rate limits and exhausted
                // quota; a billing/quota hint in the body distinguishes them.
                $body = Str::lower($response->body());
                $isQuota = str_contains($body, 'quota') || str_contains($body, 'billing')
                    || str_contains($body, 'insufficient') || str_contains($body, 'exceeded your');

                return AiCompletion::fail(
                    $isQuota ? AiCompletion::QUOTA : AiCompletion::RATE_LIMITED,
                    429,
                );
            }
            if ($response->serverError()) {
                return AiCompletion::fail(AiCompletion::ERROR, $response->status());
            }
            if (! $response->successful()) {
                return AiCompletion::fail(AiCompletion::ERROR, $response->status());
            }

            $message = $response->json('choices.0.message') ?? [];
            $answer = trim((string) ($message['content'] ?? ''));
            // Reasoning models put the answer in `reasoning` when content is empty.
            if ($answer === '') {
                $answer = trim((string) ($message['reasoning'] ?? ''));
            }

            /*
             * An answer the token limit cut off mid-word is not an answer.
             *
             * Measured: "ARUCAD burs imkanları nelerdir?" hit the 600-token
             * ceiling on every attempt and ended '...**Ekstra Burslar ve İnd'.
             * Everything before that was correct and grounded, and the last
             * fragment was a half-written word that the student then had to
             * work out was not the end of the sentence.
             *
             * `finish_reason: length` is the provider telling us exactly this
             * happened, so the tail is rolled back to the last complete
             * sentence. Only when the provider says it truncated — a natural
             * ending is never touched — and only when enough survives to still
             * be worth reading, otherwise the whole thing goes back as-is and
             * the grounding check decides.
             */
            if ($response->json('choices.0.finish_reason') === 'length' && $answer !== '') {
                $answer = $this->trimToLastSentence($answer);
            }

            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
            $usage = $response->json('usage');

            if ($answer === '') {
                $this->logAttempt(AiCompletion::ERROR, $latencyMs, null);

                return AiCompletion::fail(AiCompletion::ERROR, $response->status(), 'empty completion');
            }

            $this->logAttempt(AiCompletion::OK, $latencyMs, is_array($usage) ? $usage : null);
            // Every attempt, including a grounding regeneration, so a slow
            // answer can be explained by prompt size, output length or retry.
            app(AskTrace::class)->record('model.attempt', [
                'provider' => $this->key(),
                'latency_ms' => $latencyMs,
                'prompt_tokens' => $usage['prompt_tokens'] ?? null,
                'completion_tokens' => $usage['completion_tokens'] ?? null,
                'answer_chars' => mb_strlen($answer),
            ]);

            return AiCompletion::ok($answer, $latencyMs, is_array($usage) ? $usage : null);
        } catch (ConnectionException $e) {
            $this->logAttempt(AiCompletion::TIMEOUT, $this->elapsedMs($startedAt), null);

            return AiCompletion::fail(AiCompletion::TIMEOUT, null, $e->getMessage());
        } catch (Throwable $e) {
            $this->logAttempt(AiCompletion::ERROR, $this->elapsedMs($startedAt), null);

            return AiCompletion::fail(AiCompletion::ERROR, null, $e->getMessage());
        }
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    /**
     * Roll a truncated answer back to its last complete sentence.
     *
     * Turkish and Russian both end sentences with the same terminators as
     * English, so one rule covers all three; a newline counts too, because a
     * cut-off list is better ended at the last whole item than mid-item.
     *
     * The 60% floor is the judgement call. Cutting back past that point
     * discards so much of a grounded answer that the student is better served
     * by the long version with a ragged end than by two sentences — so in that
     * case the text is returned untouched.
     */
    private function trimToLastSentence(string $answer): string
    {
        $best = 0;
        foreach (['. ', '! ', '? ', ".\n", "!\n", "?\n", '。'] as $terminator) {
            $at = mb_strrpos($answer, $terminator);
            if ($at !== false) {
                $best = max($best, $at + 1);
            }
        }

        // A final terminator with nothing after it means it was not truncated
        // mid-sentence after all.
        foreach (['.', '!', '?'] as $terminator) {
            if (str_ends_with(rtrim($answer), $terminator)) {
                return $answer;
            }
        }

        if ($best > 0 && $best >= (int) (mb_strlen($answer) * 0.6)) {
            return rtrim(mb_substr($answer, 0, $best));
        }

        return $answer;
    }

    /**
     * Operational metadata only.
     *
     * Deliberately NOT the prompt, the messages, the answer, the API key or
     * anything from PersonalContext: an application log is read by more
     * people, kept longer and shipped further than the database it was
     * derived from, and a student's appointments have no business in it.
     * What is here is what someone debugging a slow or failing model
     * actually needs — which provider, which model, how long, what
     * happened, how many tokens.
     *
     * @param  array<string, mixed>|null  $usage
     */
    private function logAttempt(string $status, int $latencyMs, ?array $usage): void
    {
        Log::info('ai.completion', array_filter([
            'provider' => $this->key(),
            'model' => $this->model(),
            'self_hosted' => $this->isSelfHosted(),
            'status' => $status,
            'latency_ms' => $latencyMs,
            'prompt_tokens' => $usage['prompt_tokens'] ?? null,
            'completion_tokens' => $usage['completion_tokens'] ?? null,
        ], static fn ($v) => $v !== null));
    }

    public function healthCheck(): AiHealth
    {
        if (! $this->isConfigured()) {
            return AiHealth::skipped('Yapılandırılmamış.');
        }

        try {
            $request = Http::timeout(min(8, $this->timeout()))
                ->withOptions(['verify' => $this->verifySsl()]);
            if ((string) ($this->config['api_key'] ?? '') !== '') {
                $request = $request->withToken((string) $this->config['api_key']);
            }
            // /models is the standard OpenAI-compatible liveness endpoint,
            // supported by Groq, Ollama and vLLM alike.
            $response = $request->get($this->baseUrl().'/models');

            if ($response->status() === 401) {
                return AiHealth::fail('Anahtar reddedildi (yetkisiz).');
            }
            if (! $response->successful()) {
                return AiHealth::fail("Sunucu HTTP {$response->status()} döndü.");
            }

            return AiHealth::ok('Bağlantı başarılı — model listesi okundu.');
        } catch (Throwable) {
            return AiHealth::fail('Sunucuya ulaşılamadı.');
        }
    }
}
