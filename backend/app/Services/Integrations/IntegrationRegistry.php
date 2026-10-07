<?php

namespace App\Services\Integrations;

use App\Models\AppSetting;
use App\Models\IntegrationState;
use App\Models\KnowledgeDocument;
use App\Services\Agent\AruverseAgent;
use App\Services\Ai\AiProviderManager;
use App\Services\ImageModerationService;
use App\Services\RoutingService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The one list of integrations this product actually has.
 *
 * Every entry below corresponds to code that really runs — a config block in
 * `config/services.php`, an `app_settings` key, or a service class. Nothing
 * here is aspirational: an integration with no implementation would show a
 * status chip that means nothing, which is worse than not listing it.
 *
 * Status vocabulary (fixed, four values):
 *   disabled       — an operator switched it off, regardless of credentials
 *   not_configured — no credentials yet; the normal state before setup
 *   error          — the last connection test failed
 *   connected      — credentials present and nothing has failed since
 *
 * "connected" without a test date means "credentials are present and we have
 * no evidence of a problem", which is why the panel always shows the last
 * test time next to the chip rather than the chip alone.
 */
class IntegrationRegistry
{
    public const STATUS_CONNECTED = 'connected';

    public const STATUS_NOT_CONFIGURED = 'not_configured';

    public const STATUS_ERROR = 'error';

    public const STATUS_DISABLED = 'disabled';

    /** Connection tests must never hang an admin request. */
    private const TEST_TIMEOUT_SECONDS = 8;

    /** @return array<string, IntegrationDefinition> */
    public function definitions(): array
    {
        $definitions = [
            new IntegrationDefinition(
                key: 'campus_directory',
                name: 'ARUCAD 360 Directory',
                description: 'Kampüs bina/oda hiyerarşisi ve 360° tur bağlantıları. Yer pinleri bu kaynaktan gelmez.',
                envKeys: ['CAMPUS_DIRECTORY_BASE_URL', 'CAMPUS_DIRECTORY_API_KEY'],
                configured: fn () => filled(config('services.campus_directory.api_key')),
                tester: fn () => $this->testCampusDirectory(),
                secretHint: fn () => (string) config('services.campus_directory.api_key'),
            ),
            new IntegrationDefinition(
                key: 'groq',
                name: 'Groq (Birincil AI Sağlayıcı)',
                description: 'ARUVERSE\'in birincil üretim AI sağlayıcısı. Asistan yanıtları ve afiş→etkinlik taslağı buradan gelir. Kota/hız limiti durumunda kontrollü şekilde yönetilir.',
                envKeys: ['GROQ_API_KEY', 'GROQ_CHAT_MODEL', 'GROQ_VISION_MODEL'],
                configured: fn () => filled(config('services.groq.key')),
                tester: fn () => $this->testGroq(),
                secretHint: fn () => (string) config('services.groq.key'),
            ),
            new IntegrationDefinition(
                key: 'entra',
                name: 'Microsoft Entra ID',
                description: 'Kurumsal hesapla oturum açma (public client + PKCE).',
                envKeys: ['ENTRA_TENANT_ID', 'ENTRA_CLIENT_ID', 'ENTRA_REDIRECT_URI'],
                configured: fn () => filled($this->entraTenantId()) && filled($this->entraClientId()),
                tester: fn () => $this->testEntra(),
                // Tenant/client ids are public client identifiers, not
                // secrets — there is no stored secret to mask here.
                secretHint: null,
                managedVia: 'admin',
            ),
            new IntegrationDefinition(
                key: 'wordpress',
                name: 'WordPress',
                description: 'Şu an kullanılmıyor. Entegrasyon mimarisi korundu; ileride gerekirse yeniden etkinleştirilebilir.',
                envKeys: [],
                configured: fn () => filled(AppSetting::getValue('wordpress.siteUrl'))
                    && filled(AppSetting::getValue('wordpress.apiToken')),
                tester: fn () => $this->testWordpress(),
                secretHint: fn () => (string) AppSetting::getValue('wordpress.apiToken'),
                managedVia: 'admin',
                // Not in use (spec §5). Reported Disabled regardless of config.
                builtinDisabledReason: 'Ürün kapsamında değil.',
            ),
            new IntegrationDefinition(
                key: 'routing',
                name: 'OSRM Rota Motoru (yürüme + araç)',
                description: 'Yürüme ve araç rotaları ayrı OSRM grafiklerinden gelir (foot / car). Yapılandırılmayan mod için API 501 döner, uydurma rota üretmez.',
                envKeys: ['ROUTING_BASE_URL', 'ROUTING_DRIVING_BASE_URL', 'ROUTING_VERIFY_SSL'],
                configured: fn () => RoutingService::isConfigured(),
                tester: fn () => $this->testRouting(),
            ),
            new IntegrationDefinition(
                key: 'openai_moderation',
                name: 'OpenAI Moderation',
                description: 'İsteğe bağlı harici içerik denetimi. Varsayılan KAPALI — denetim kendi sunucumuzda yapılır.',
                envKeys: ['OPENAI_API_KEY', 'MODERATION_OPENAI_ENABLED', 'MODERATION_ENDPOINT'],
                configured: fn () => filled(config('services.moderation.openai_key')),
                tester: fn () => $this->testOpenAiModeration(),
                secretHint: fn () => (string) config('services.moderation.openai_key'),
            ),
            // NOTE: the "Local Visual Classifier" panel entry was removed on
            // request — ARUVERSE already has its own moderation system
            // (ImageModerationService / LocalMediaClassifier), which is
            // unaffected by this and keeps running. Only the redundant panel
            // listing is gone.
            new IntegrationDefinition(
                key: 'fcm',
                name: 'FCM/APNs (yalnızca teslim kanalı)',
                description: 'Bildirim mimarisinin ÇEKİRDEĞİ değil. Uygulama kapalıyken OS seviyesinde push için Apple/Google kanalı; karar ve içerik bizim sistemimizde. Boşsa uygulama içi gelen kutusu çalışmaya devam eder.',
                envKeys: ['FIREBASE_PROJECT_ID', 'FIREBASE_CLIENT_EMAIL', 'FIREBASE_PRIVATE_KEY', 'FCM_REQUIRED'],
                configured: fn () => filled(config('services.fcm.project_id'))
                    && filled(config('services.fcm.client_email'))
                    && filled(config('services.fcm.private_key')),
                // Deliberately no remote test: FCM only answers a request
                // signed with the service-account key, and minting that JWT
                // to prove liveness would send a real push credential for a
                // button press. Config validation is the honest check.
                tester: null,
                secretHint: fn () => (string) config('services.fcm.private_key'),
            ),
            new IntegrationDefinition(
                key: 'mail',
                name: 'E-posta (SMTP)',
                description: 'Davet, bildirim ve sistem e-postaları. `log` sürücüsü gerçek gönderim yapmaz.',
                envKeys: ['MAIL_MAILER', 'MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_FROM_ADDRESS'],
                configured: fn () => $this->mailConfigured(),
                // Real connection test: open the SMTP socket and read the
                // server greeting. Proves reachability without sending a
                // message (which would email a real person). ARUCAD's own
                // mail server — self-hosted, no external email provider.
                tester: fn () => $this->testMail(),
                secretHint: fn () => (string) config('mail.mailers.smtp.password'),
                selfHosted: true,
            ),
            new IntegrationDefinition(
                key: 'site_knowledge',
                name: 'Site Bilgi Tarayıcı (Kendi Servisimiz)',
                description: 'Public ARUCAD web sitelerini tarayıp Ask ARUVERSE için güncel bilgi tabanı oluşturan kendi servisimiz. Taranacak siteler AICAD Tarama Siteleri ekranından yönetilir. Harici arama/RAG servisi kullanmaz.',
                envKeys: ['KNOWLEDGE_CRAWLER_ENABLED', 'KNOWLEDGE_REFRESH_HOURS', 'KNOWLEDGE_MAX_PAGES'],
                // Self-hosted: "configured" means the crawler is enabled and
                // has actually stored pages, not that a credential is present.
                configured: fn () => (bool) config('knowledge.enabled') && KnowledgeDocument::query()->exists(),
                tester: fn () => $this->testSiteKnowledge(),
                secretHint: null,
                selfHosted: true,
            ),

            // ---- ARUCAD-owned internal services (no external dependency) ----

            new IntegrationDefinition(
                key: 'local_ai',
                name: 'Yerel AI (Opsiyonel Yedek)',
                description: 'Opsiyonel. ARUVERSE şu an Groq kullanıyor. Yerel AI ileride, kendi sunucumuzda çalışan opsiyonel bir yedek (Ollama/vLLM) olarak etkinleştirilebilir. Yapılandırılmaması bir hata veya eksik bağımlılık DEĞİLDİR.',
                envKeys: ['LOCAL_AI_BASE_URL', 'LOCAL_AI_MODEL'],
                configured: fn () => (bool) (app(AiProviderManager::class)->get('local')?->isConfigured()),
                tester: fn () => $this->testLocalAi(),
                secretHint: null,
                selfHosted: true,
            ),
            new IntegrationDefinition(
                key: 'agent',
                name: 'ARUVERSE Agent',
                description: 'Soruyu anlayıp hangi iç kaynağın kullanılacağına karar veren kontrollü yönlendirici. Araçlar açık ve kısıtlı; harici servis kullanmaz.',
                envKeys: [],
                configured: fn () => true, // in-process, always available
                tester: fn () => $this->testAgent(),
                secretHint: null,
                selfHosted: true,
            ),
            new IntegrationDefinition(
                key: 'notifications',
                name: 'Bildirim Servisi (Kendi Altyapımız)',
                description: 'Bildirim üretimi, hedefleme, gelen kutusu ve gerçek-zamanlı iletim ARUCAD altyapısında (Laravel Reverb WebSocket). Çekirdek Firebase değildir.',
                envKeys: ['BROADCAST_CONNECTION', 'REVERB_HOST'],
                configured: fn () => config('broadcasting.default') === 'reverb'
                    && filled(config('broadcasting.connections.reverb.key')),
                tester: fn () => $this->testNotifications(),
                secretHint: null,
                selfHosted: true,
            ),
            new IntegrationDefinition(
                key: 'logging',
                name: 'Yerel Kayıt / Gözlemlenebilirlik',
                description: 'Hata ve olay kayıtları ARUCAD sunucusunda tutulur (Laravel log + admin denetim kaydı). Sentry zorunlu değildir.',
                envKeys: ['LOG_CHANNEL', 'LOG_LEVEL'],
                configured: fn () => true, // always on; local files
                tester: fn () => $this->testLogging(),
                secretHint: null,
                selfHosted: true,
            ),

            // NOTE: the Sentry panel entry was removed on request. Local
            // logging (the `logging` integration) is the observability path.
            // The Sentry SDK remains installed but stays a silent no-op with
            // an empty DSN, so nothing breaks and it can be re-listed later.
        ];

        $byKey = [];
        foreach ($definitions as $definition) {
            $byKey[$definition->key] = $definition;
        }

        return $byKey;
    }

    public function find(string $key): ?IntegrationDefinition
    {
        return $this->definitions()[$key] ?? null;
    }

    /**
     * Every integration as the panel and the API present it.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $states = IntegrationState::query()->get()->keyBy('key');

        $rows = [];
        foreach ($this->definitions() as $definition) {
            $rows[] = $this->present($definition, $states->get($definition->key));
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    public function present(IntegrationDefinition $definition, ?IntegrationState $state): array
    {
        $configured = $definition->isConfigured();
        // Operator kill switch OR a product-level "not in use" flag.
        $disabled = ($state?->isDisabled() ?? false) || $definition->builtinDisabledReason !== null;

        return [
            'key' => $definition->key,
            'name' => $definition->name,
            'description' => $definition->description,
            'status' => $this->status($configured, $disabled, $state),
            'configured' => $configured,
            'enabled' => ! $disabled,
            'selfHosted' => $definition->selfHosted,
            'disabledReason' => $definition->builtinDisabledReason,
            'managedVia' => $definition->managedVia,
            'remotelyTestable' => $definition->isRemotelyTestable(),
            // Names only. Their values are never read into this payload.
            'envKeys' => $definition->envKeys,
            'secretMasked' => $definition->maskedSecret(),
            'lastTestAt' => $state?->last_test_at?->toIso8601String(),
            'lastTestOk' => $state?->last_test_ok,
            'lastSuccessAt' => $state?->last_success_at?->toIso8601String(),
            'lastError' => $state?->last_error,
            'lastErrorAt' => $state?->last_error_at?->toIso8601String(),
        ];
    }

    private function status(bool $configured, bool $disabled, ?IntegrationState $state): string
    {
        // Disabled outranks everything: an operator who switched an
        // integration off should see that, not a stale error from before.
        if ($disabled) {
            return self::STATUS_DISABLED;
        }
        if (! $configured) {
            return self::STATUS_NOT_CONFIGURED;
        }
        if ($state?->last_test_ok === false) {
            return self::STATUS_ERROR;
        }

        return self::STATUS_CONNECTED;
    }

    /**
     * Run one integration's connection test and record the outcome.
     *
     * Never throws: a provider being down must not 500 the admin panel.
     */
    public function test(IntegrationDefinition $definition, ?string $actorEmail = null): IntegrationTestResult
    {
        if (! $definition->isConfigured()) {
            $result = IntegrationTestResult::skipped(
                'Kimlik bilgisi girilmemiş — test çalıştırılmadı.'
            );
            $this->record($definition, $result, $actorEmail);

            return $result;
        }

        if (! $definition->isRemotelyTestable()) {
            // Honest wording: we verified configuration, not connectivity.
            $result = IntegrationTestResult::success(
                'Yapılandırma eksiksiz. Bu sağlayıcı için canlı bağlantı testi yapılmıyor.'
            );
            $this->record($definition, $result, $actorEmail);

            return $result;
        }

        try {
            $result = ($definition->tester)();
        } catch (Throwable $e) {
            // The exception message can quote the request (and therefore the
            // key), so it goes to the log and never to the operator.
            $result = IntegrationTestResult::failure(
                'Bağlantı testi beklenmeyen bir hatayla durdu. Ayrıntı sunucu kayıtlarında.',
                ['exception' => $e::class, 'message' => $e->getMessage()],
            );
        }

        $this->record($definition, $result, $actorEmail);

        return $result;
    }

    private function record(IntegrationDefinition $definition, IntegrationTestResult $result, ?string $actorEmail): void
    {
        $now = now();
        $attributes = [
            'last_test_at' => $now,
            // A skipped test is not a failure; leaving last_test_ok null
            // keeps the row out of the Error status.
            'last_test_ok' => $result->skipped ? null : $result->ok,
            'updated_by_email' => $actorEmail,
        ];

        if ($result->ok) {
            $attributes['last_success_at'] = $now;
            $attributes['last_error'] = null;
            $attributes['last_error_at'] = null;
        } elseif (! $result->skipped) {
            $attributes['last_error'] = mb_substr($result->message, 0, 500);
            $attributes['last_error_at'] = $now;

            Log::warning('Integration connection test failed', [
                'integration' => $definition->key,
            ] + $this->redact($result->logContext));
        }

        IntegrationState::updateOrCreate(['key' => $definition->key], $attributes);
    }

    /**
     * Last line of defence for the server log.
     *
     * Log context is written by the testers below and already avoids
     * credentials, but a provider message can quote the request back. Any
     * value that looks like a long opaque token is replaced.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function redact(array $context): array
    {
        array_walk_recursive($context, function (&$value): void {
            if (! is_string($value)) {
                return;
            }
            $value = preg_replace('/\b[A-Za-z0-9_\-]{24,}\b/', '[redacted]', $value) ?? '[redacted]';
        });

        return $context;
    }

    // ---------------------------------------------------------------- tests

    private function testCampusDirectory(): IntegrationTestResult
    {
        $base = rtrim((string) config('services.campus_directory.base_url'), '/');
        $response = Http::acceptJson()
            ->withToken((string) config('services.campus_directory.api_key'))
            ->timeout(self::TEST_TIMEOUT_SECONDS)
            ->withOptions(['verify' => (bool) config('services.campus_directory.verify_ssl', true)])
            ->get($base.'/api/integration/directory');

        if ($response->status() === 401 || $response->status() === 403) {
            return IntegrationTestResult::failure('API anahtarı reddedildi (yetkisiz).', ['status' => $response->status()]);
        }
        if (! $response->successful()) {
            return IntegrationTestResult::failure(
                "Sunucu HTTP {$response->status()} döndü.",
                ['status' => $response->status()],
            );
        }

        $rooms = $response->json('rooms');
        if (! is_array($rooms)) {
            return IntegrationTestResult::failure('Yanıt beklenen "rooms" listesini içermiyor.');
        }

        return IntegrationTestResult::success(sprintf('Bağlantı başarılı — %d oda okundu.', count($rooms)));
    }

    private function testGroq(): IntegrationTestResult
    {
        $response = Http::acceptJson()
            ->withToken((string) config('services.groq.key'))
            ->timeout(self::TEST_TIMEOUT_SECONDS)
            ->withOptions(['verify' => (bool) config('services.groq.verify_ssl', true)])
            ->get('https://api.groq.com/openai/v1/models');

        if ($response->status() === 401) {
            return IntegrationTestResult::failure('API anahtarı reddedildi (yetkisiz).', ['status' => 401]);
        }
        if (! $response->successful()) {
            return IntegrationTestResult::failure("Sunucu HTTP {$response->status()} döndü.", ['status' => $response->status()]);
        }

        return IntegrationTestResult::success('Bağlantı başarılı — model listesi okundu.');
    }

    private function testEntra(): IntegrationTestResult
    {
        // The OIDC discovery document is public and tenant-scoped: it proves
        // the tenant id resolves without sending any credential anywhere.
        $tenant = rawurlencode($this->entraTenantId());
        $response = Http::acceptJson()
            ->timeout(self::TEST_TIMEOUT_SECONDS)
            ->withOptions(['verify' => (bool) config('services.entra.verify_ssl', true)])
            ->get("https://login.microsoftonline.com/{$tenant}/v2.0/.well-known/openid-configuration");

        if (! $response->successful()) {
            return IntegrationTestResult::failure(
                "Tenant bulunamadı ya da erişilemedi (HTTP {$response->status()}).",
                ['status' => $response->status()],
            );
        }
        if (! is_string($response->json('issuer'))) {
            return IntegrationTestResult::failure('Discovery yanıtı beklenen "issuer" alanını içermiyor.');
        }

        return IntegrationTestResult::success('Tenant doğrulandı — OIDC discovery okundu.');
    }

    private function testWordpress(): IntegrationTestResult
    {
        $site = rtrim((string) AppSetting::getValue('wordpress.siteUrl'), '/');
        $response = Http::acceptJson()
            ->withToken((string) AppSetting::getValue('wordpress.apiToken'))
            ->timeout(self::TEST_TIMEOUT_SECONDS)
            ->get($site.'/wp-json/wp/v2/types');

        if (in_array($response->status(), [401, 403], true)) {
            return IntegrationTestResult::failure('Token reddedildi (yetkisiz).', ['status' => $response->status()]);
        }
        if (! $response->successful()) {
            return IntegrationTestResult::failure("Site HTTP {$response->status()} döndü.", ['status' => $response->status()]);
        }

        return IntegrationTestResult::success('Bağlantı başarılı — WP REST API yanıt verdi.');
    }

    /**
     * Both graphs are tested, not just the pedestrian one.
     *
     * Walking and vehicles run on separate OSRM instances (foot.lua and
     * car.lua graphs of the same Cyprus extract). Testing only
     * ROUTING_BASE_URL reported the integration healthy while every car
     * and bus route in the app was failing, which is the opposite of what
     * a connection test is for.
     */
    private function testRouting(): IntegrationTestResult
    {
        $graphs = [
            'Yürüme' => RoutingService::baseForMode('walking'),
            'Araç' => RoutingService::baseForMode('driving'),
        ];

        $ok = [];
        foreach ($graphs as $label => $base) {
            if ($base === '') {
                continue;
            }
            $failure = $this->probeRoutingGraph($label, $base);
            if ($failure !== null) {
                return $failure;
            }
            $ok[] = $label;
        }

        if ($ok === []) {
            return IntegrationTestResult::failure(
                'Hiçbir rota grafiği yapılandırılmamış (ROUTING_BASE_URL / ROUTING_DRIVING_BASE_URL).'
            );
        }

        $missing = array_diff(array_keys($graphs), $ok);
        $message = 'Bağlantı başarılı — test rotası hesaplandı: '.implode(', ', $ok).'.';
        if ($missing !== []) {
            // Not a failure: a campus may deliberately run pedestrian
            // routing only. But it must be visible, because that mode
            // answers 501 in the app.
            $message .= ' Yapılandırılmamış: '.implode(', ', $missing).'.';
        }

        return IntegrationTestResult::success($message);
    }

    private function probeRoutingGraph(string $label, string $base): ?IntegrationTestResult
    {
        // Two points a few metres apart on the main campus: a real routable
        // query, cheap enough to run on demand. The profile in the path is
        // cosmetic — a single-profile OSRM ignores it — so this proves the
        // host answers, and WHICH graph answers is decided by the host.
        $path = '/route/v1/driving/33.321303,35.337305;33.321358,35.337395?overview=false';

        try {
            $response = Http::acceptJson()
                ->timeout(self::TEST_TIMEOUT_SECONDS)
                ->withOptions(['verify' => (bool) config('services.routing.verify_ssl', true)])
                ->get($base.$path);
        } catch (Throwable $e) {
            return IntegrationTestResult::failure("{$label}: sunucuya ulaşılamadı.", ['error' => $e->getMessage()]);
        }

        if (! $response->successful()) {
            return IntegrationTestResult::failure(
                "{$label}: sunucu HTTP {$response->status()} döndü.",
                ['status' => $response->status()],
            );
        }
        if ($response->json('code') !== 'Ok') {
            return IntegrationTestResult::failure("{$label}: OSRM beklenen \"Ok\" kodunu döndürmedi.");
        }

        return null;
    }

    private function testOpenAiModeration(): IntegrationTestResult
    {
        if (! config('services.moderation.enabled')) {
            return IntegrationTestResult::success(
                'Anahtar tanımlı ancak harici denetim kapalı (MODERATION_OPENAI_ENABLED=false). İçerik ARUCAD dışına gönderilmiyor.'
            );
        }

        $response = Http::acceptJson()
            ->withToken((string) config('services.moderation.openai_key'))
            ->timeout(self::TEST_TIMEOUT_SECONDS)
            ->post((string) config('services.moderation.endpoint'), [
                'model' => (string) config('services.moderation.model'),
                'input' => 'ping',
            ]);

        if ($response->status() === 401) {
            return IntegrationTestResult::failure('API anahtarı reddedildi (yetkisiz).', ['status' => 401]);
        }
        if (! $response->successful()) {
            return IntegrationTestResult::failure("Sunucu HTTP {$response->status()} döndü.", ['status' => $response->status()]);
        }

        return IntegrationTestResult::success('Bağlantı başarılı — denetim uç noktası yanıt verdi.');
    }

    private function testLocalAi(): IntegrationTestResult
    {
        $provider = app(AiProviderManager::class)->get('local');
        if ($provider === null || ! $provider->isConfigured()) {
            return IntegrationTestResult::skipped('Yerel AI adresi girilmemiş (LOCAL_AI_BASE_URL).');
        }
        $health = $provider->healthCheck();

        return $health->ok
            ? IntegrationTestResult::success($health->message)
            : IntegrationTestResult::failure($health->message);
    }

    private function testAgent(): IntegrationTestResult
    {
        $tools = array_keys(app(AruverseAgent::class)->tools());

        return IntegrationTestResult::success(
            sprintf('Agent hazır. %d iç araç kayıtlı: %s.', count($tools), implode(', ', $tools)),
        );
    }

    private function testNotifications(): IntegrationTestResult
    {
        if (config('broadcasting.default') !== 'reverb') {
            return IntegrationTestResult::skipped('Gerçek-zamanlı iletim için BROADCAST_CONNECTION=reverb gerekir. Gelen kutusu yine de çalışır.');
        }
        if (! filled(config('broadcasting.connections.reverb.key'))) {
            return IntegrationTestResult::failure('Reverb anahtarı yapılandırılmamış.');
        }

        return IntegrationTestResult::success('Bildirim çekirdeği ARUCAD altyapısında (Reverb) yapılandırılmış.');
    }

    private function testLogging(): IntegrationTestResult
    {
        try {
            Log::info('ARUVERSE logging health check');
        } catch (Throwable $e) {
            return IntegrationTestResult::failure('Log yazılamadı: '.$e->getMessage());
        }

        return IntegrationTestResult::success(
            sprintf('Yerel kayıt aktif (kanal: %s).', (string) config('logging.default')),
        );
    }

    /**
     * Open the SMTP socket and read the greeting. Proves the ARUCAD mail
     * server is reachable without sending a message or authenticating (which
     * would transmit the password). Full-auth verification is left to a real
     * send + the mail log.
     */
    private function testMail(): IntegrationTestResult
    {
        $host = (string) config('mail.mailers.smtp.host');
        $port = (int) config('mail.mailers.smtp.port', 587);
        if ($host === '') {
            return IntegrationTestResult::skipped('SMTP sunucusu girilmemiş (MAIL_HOST).');
        }

        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($host, $port, $errno, $errstr, 6);
        if ($socket === false) {
            return IntegrationTestResult::failure(
                "SMTP sunucusuna ulaşılamadı ($host:$port).",
                ['errno' => $errno, 'errstr' => $errstr],
            );
        }
        stream_set_timeout($socket, 6);
        $greeting = (string) fgets($socket, 512);
        fclose($socket);

        if (! str_starts_with(trim($greeting), '220')) {
            return IntegrationTestResult::failure('SMTP sunucusu beklenen 220 karşılamasını vermedi.');
        }

        return IntegrationTestResult::success("SMTP sunucusuna ulaşıldı ($host:$port).");
    }

    private function testSiteKnowledge(): IntegrationTestResult
    {
        if (! config('knowledge.enabled')) {
            return IntegrationTestResult::failure('Tarayıcı kapalı (KNOWLEDGE_CRAWLER_ENABLED=false).');
        }
        $count = KnowledgeDocument::query()->count();
        if ($count === 0) {
            return IntegrationTestResult::skipped('Henüz sayfa taranmamış. `php artisan knowledge:crawl` çalıştırın.');
        }
        $latest = KnowledgeDocument::query()->max('fetched_at');
        $pdfs = KnowledgeDocument::query()->where('content_type', 'application/pdf')->count();
        $pdfFailures = KnowledgeDocument::query()
            ->where('content_type', 'application/pdf')
            ->where('document_status', '!=', 'indexed')
            ->count();

        if ($pdfFailures > 0) {
            return IntegrationTestResult::failure(sprintf(
                '%d kaynak (%d PDF) bilgi tabanında; %d PDF metni çıkarılamadı. Son tarama: %s.',
                $count, $pdfs, $pdfFailures, $latest ?? 'bilinmiyor',
            ));
        }

        return IntegrationTestResult::success(
            sprintf('%d kaynak (%d PDF) bilgi tabanında. Son tarama: %s.', $count, $pdfs, $latest ?? 'bilinmiyor'),
        );
    }

    // ------------------------------------------------------------- helpers

    /**
     * Entra is editable from the panel, so `app_settings` wins over the env
     * default — the same precedence AuthController already applies.
     */
    private function entraTenantId(): string
    {
        return (string) (AppSetting::getValue('entra.tenantId') ?: config('services.entra.tenant_id'));
    }

    private function entraClientId(): string
    {
        return (string) (AppSetting::getValue('entra.clientId') ?: config('services.entra.client_id'));
    }

    private function mailConfigured(): bool
    {
        $mailer = (string) config('mail.default');
        if ($mailer === '' || $mailer === 'log' || $mailer === 'array') {
            return false;
        }
        if ($mailer !== 'smtp') {
            // ses/postmark/resend carry their own credentials elsewhere;
            // treat a non-log mailer as configured rather than guessing.
            return true;
        }

        return filled(config('mail.mailers.smtp.host'))
            && filled(config('mail.from.address'));
    }

    /**
     * Operator kill switch. Returns the refreshed row so the caller can
     * report the new status without a second query.
     */
    public function setEnabled(IntegrationDefinition $definition, bool $enabled, ?string $actorEmail): IntegrationState
    {
        return tap(IntegrationState::updateOrCreate(
            ['key' => $definition->key],
            ['enabled' => $enabled, 'updated_by_email' => $actorEmail],
        ), fn (IntegrationState $state) => $state->refresh());
    }
}
