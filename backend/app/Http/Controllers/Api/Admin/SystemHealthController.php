<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Models\KnowledgeChunk;
use App\Services\Ai\AiBudget;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\PersonalDataCapabilities;
use App\Services\Knowledge\EmbeddingClient;
use App\Services\RoutingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

// A single read-only status page composing checks that already exist
// elsewhere in the codebase (RoutingService::isConfigured(),
// Admin\SettingsController's own configured-booleans, HealthController's
// database probe) — deliberately just status chips, not a
// monitoring/alerting platform.
class SystemHealthController extends Controller
{
    use ApiResponds;

    public function index(): JsonResponse
    {
        return $this->ok([
            'database' => $this->databaseStatus(),
            'routingConfigured' => RoutingService::isConfigured(),
            'moderationConfigured' => true,
            'entraConfigured' => (bool) AppSetting::getValue('entra.tenantId')
                && (bool) AppSetting::getValue('entra.clientId'),
            'wordpressConfigured' => filled(AppSetting::getValue('wordpress.apiToken')),
            // Any provider at all, not just Groq. This said "AI is not
            // configured" on a campus running entirely on its own vLLM
            // server, because it only ever looked at the Groq key.
            'aiConfigured' => app(AiProviderManager::class)->primary() !== null,
            'broadcastingConfigured' => (bool) config('broadcasting.connections.reverb.key')
                && config('broadcasting.default') === 'reverb',
            'assistant' => $this->assistantStatus(),
        ]);
    }

    /**
     * Capacity and degradation state of Ask ARUVERSE.
     *
     * Here rather than on the public `/api/v1/health`, and the
     * difference matters. A campus running with its daily model
     * allowance already spent looks completely normal from outside —
     * questions still get answered, from indexed sources — so somebody
     * has to be able to ask. But "how much of today's quota is left" and
     * "how many calls are in flight" tell an outsider exactly when the
     * assistant is one push away from degrading, and the public endpoint
     * already refuses to name internal hosts for the same reason.
     *
     * `mode` is what a student would experience right now: `normal`
     * while the model can be called, `knowledge_only` once the budget is
     * spent.
     *
     * @return array<string, mixed>
     */
    private function assistantStatus(): array
    {
        $budget = app(AiBudget::class)->snapshot();
        $dailyCap = (int) $budget['daily_cap'];

        return [
            'mode' => $dailyCap > 0 && $budget['used_today'] >= $dailyCap
                ? 'knowledge_only'
                : 'normal',
            'requestsToday' => $budget['used_today'],
            'dailyCap' => $dailyCap ?: null,
            'inFlight' => $budget['in_flight'],
            'maxConcurrent' => $budget['max_concurrent'] ?: null,
            // Semantic search degrades to keyword matching without the
            // classifier, which is a quality loss nobody would otherwise
            // see — answers simply get worse.
            'semanticSearch' => app(EmbeddingClient::class)->isEnabled() ? 'on' : 'off',
            'indexedPassages' => $this->indexedPassages(),
            'model' => $this->modelStatus(),
            /*
             * Which personal-data systems the assistant can actually reach.
             *
             * Here because "why did AICAD tell me it cannot see my timetable"
             * is a support question, and the answer is a deployment fact
             * rather than a bug. Without this, working it out means reading
             * config/ai.php. Values only — the registry says what is
             * connected, never what is in it.
             */
            'capabilities' => app(PersonalDataCapabilities::class)->snapshot(),
        ];
    }

    /**
     * Which model is answering, and whether it can be reached.
     *
     * The generative model runs on a separate host now (deploy/ai/), so
     * "the web server is fine" stopped being evidence that the assistant
     * works: the GPU box can be down, or serving a different model than
     * the one configured here, while everything on this page stays green.
     *
     * The probe is the provider's own /models liveness call, not a
     * completion — cheap enough to run on a status page, and it answers
     * the question that matters ("is the endpoint there?") without
     * spending a GPU slot on it.
     *
     * NO SECRETS: the endpoint host is shown because an operator needs it
     * to debug, the API key never is.
     *
     * @return array<string, mixed>
     */
    private function modelStatus(): array
    {
        $manager = app(AiProviderManager::class);
        $primary = $manager->primary();

        $startedAt = microtime(true);
        $health = $primary?->healthCheck();
        $latencyMs = $primary === null
            ? null
            : (int) round((microtime(true) - $startedAt) * 1000);

        return [
            'provider' => $primary?->key(),
            'label' => $primary?->label(),
            'selfHosted' => $primary?->isSelfHosted(),
            // The configured model id, so a mismatch between what the
            // panel thinks is running and what vLLM actually loaded is
            // visible rather than something to guess at.
            'name' => $primary === null
                ? null
                : (string) config('ai.providers.'.$primary->key().'.model'),
            'endpoint' => $primary === null
                ? null
                : $this->safeEndpoint((string) config('ai.providers.'.$primary->key().'.base_url')),
            'reachable' => $health?->ok,
            'detail' => $health?->message,
            'latencyMs' => $latencyMs,
            'fallback' => $manager->fallback()?->key(),
            // Degraded-mode state in one word, matching what a student
            // would currently experience.
            'liveMode' => $manager->liveModeLabel(),
            // Why an external provider would be skipped, if one is set.
            'externalAllowed' => (bool) config('ai.privacy.allow_external', false),
            'externalAllowedWithPersonalData' => (bool) config(
                'ai.privacy.allow_external_with_personal_data', false
            ),
        ];
    }

    /**
     * Host and port only. A base URL can carry a key in a query string on
     * some gateways, and this page is read by more people than hold that
     * key.
     */
    private function safeEndpoint(string $baseUrl): ?string
    {
        if (trim($baseUrl) === '') {
            return null;
        }

        $parts = parse_url($baseUrl);
        if (! is_array($parts) || ! isset($parts['host'])) {
            return null;
        }

        return ($parts['scheme'] ?? 'http').'://'.$parts['host']
            .(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /**
     * Null rather than an exception when the table is not there.
     *
     * A status page that 500s is worse than useless: it is the thing you
     * open when something is already wrong. This one was measured taking
     * the endpoint down on a database without the passages table.
     */
    private function indexedPassages(): ?int
    {
        try {
            return KnowledgeChunk::query()->count();
        } catch (\Throwable) {
            return null;
        }
    }

    private function databaseStatus(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
