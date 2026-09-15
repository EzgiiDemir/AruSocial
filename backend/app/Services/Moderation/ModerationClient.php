<?php

namespace App\Services\Moderation;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Adapter for the self-hosted moderation gateway.
 *
 * It deliberately implements the same ProviderResult boundary used by the
 * existing ContentModerator, so controllers and their save/broadcast logic
 * do not need to change. When the gateway is disabled, the existing OpenAI
 * adapter remains an optional migration fallback.
 */
final class ModerationClient
{
    public function __construct(
        private readonly OpenAiModerationClient $legacy = new OpenAiModerationClient,
        private readonly SelfHostedTextClient $selfHostedText = new SelfHostedTextClient,
    ) {}

    public function usesGateway(): bool
    {
        return (bool) config('moderation.enabled', false)
            && trim((string) config('moderation.base_url')) !== '';
    }

    public function isConfigured(): bool
    {
        return $this->usesGateway()
            || $this->selfHostedText->isConfigured()
            || $this->legacy->isConfigured();
    }

    /**
     * @param  list<string>  $imageUrls  Data URIs or public image URLs.
     * @param  list<array{path:string,name?:string}>  $files  Private upload paths.
     */
    public function inspect(
        ?string $text,
        array $imageUrls = [],
        array $files = [],
        string $surface = 'content',
    ): ProviderResult {
        if (! $this->usesGateway()) {
            if ($files !== []) {
                return ProviderResult::unavailable('not_configured');
            }

            // Self-hosted first, and for text-only submissions it is the
            // whole answer. The project runs without a paid moderation
            // API on purpose, and the remote account has no quota — so
            // preferring it is also the difference between a working
            // semantic layer and none.
            //
            // Media never reaches here: MediaController routes images and
            // video to the calibrated visual classifier before this, and
            // this client is not a vision model.
            if ($imageUrls === [] && $this->selfHostedText->isConfigured()) {
                return $this->selfHostedText->inspect($text);
            }

            return $this->legacy->inspect($text, $imageUrls);
        }

        $request = $this->http()->asMultipart();
        $handles = [];

        try {
            foreach ($files as $index => $file) {
                $path = $file['path'] ?? '';
                if (! is_string($path) || ! is_file($path)) {
                    return ProviderResult::unavailable('invalid_private_file');
                }
                $handle = fopen($path, 'rb');
                if ($handle === false) {
                    return ProviderResult::unavailable('unreadable_private_file');
                }
                $handles[] = $handle;
                $request = $request->attach('files', $handle, $file['name'] ?? basename($path));
            }

            foreach ($imageUrls as $index => $url) {
                $decoded = $this->decodeDataUri($url);
                if ($decoded === null) {
                    // The gateway intentionally does not fetch arbitrary
                    // remote media. Feed attachments on this application are
                    // local and are inlined before reaching this boundary.
                    continue;
                }
                $request = $request->attach(
                    'files',
                    $decoded['bytes'],
                    'inline-'.$index.'.'.$decoded['extension'],
                    ['Content-Type' => $decoded['mime']],
                );
            }

            $response = $request->post('/v1/moderate', [
                ['name' => 'surface', 'contents' => $surface],
                ['name' => 'text', 'contents' => $text ?? ''],
                ['name' => 'urls_json', 'contents' => json_encode(
                    $this->extractUrls((string) $text),
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                )],
            ]);
        } catch (\Throwable $e) {
            Log::warning('moderation.gateway_exception', ['type' => $e::class]);

            return ProviderResult::unavailable('gateway_exception');
        } finally {
            foreach ($handles as $handle) {
                if (is_resource($handle)) {
                    fclose($handle);
                }
            }
        }

        if (! $response->successful()) {
            Log::warning('moderation.gateway_http', ['status' => $response->status()]);

            return ProviderResult::unavailable('gateway_http_'.$response->status());
        }

        $payload = $response->json();
        if (! is_array($payload) || ! in_array($payload['decision'] ?? null, ['allow', 'block', 'error'], true)) {
            return ProviderResult::unavailable('gateway_malformed');
        }

        if ($payload['decision'] === 'error') {
            return ProviderResult::unavailable('gateway_required_provider');
        }

        return ProviderResult::fromGateway(
            decision: $payload['decision'],
            categories: is_array($payload['categories'] ?? null) ? $payload['categories'] : [],
            strikeRecommended: (bool) ($payload['strike_recommended'] ?? false),
            degraded: (bool) ($payload['degraded'] ?? false),
        );
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('moderation.base_url'), '/'))
            ->timeout((float) config('moderation.timeout', 15))
            ->connectTimeout(5)
            ->acceptJson();
    }

    /** @return list<string> */
    private function extractUrls(string $text): array
    {
        preg_match_all('~https?://[^\s<>"\']+~iu', $text, $matches);

        return array_values(array_unique(array_map(
            static fn (string $url): string => rtrim($url, '.,;:!?)]}'),
            $matches[0] ?? [],
        )));
    }

    /** @return array{bytes:string,mime:string,extension:string}|null */
    private function decodeDataUri(string $uri): ?array
    {
        if (! preg_match('~^data:(image/(?:jpeg|png|webp|gif));base64,([A-Za-z0-9+/=]+)$~', $uri, $m)) {
            return null;
        }
        $bytes = base64_decode($m[2], true);
        if ($bytes === false) {
            return null;
        }

        return [
            'bytes' => $bytes,
            'mime' => $m[1],
            'extension' => $m[1] === 'image/jpeg' ? 'jpg' : substr($m[1], 6),
        ];
    }
}
