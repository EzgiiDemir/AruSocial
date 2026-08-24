<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HttpFcmClient implements FcmClient
{
    public function __construct(
        private string $projectId,
        private string $clientEmail,
        private string $privateKey,
    ) {}

    public function send(string $deviceToken, string $title, string $body, array $data): FcmSendResult
    {
        try {
            $accessToken = $this->accessToken();
        } catch (\Throwable $e) {
            Log::warning('FCM OAuth failed', ['error' => $e->getMessage()]);

            throw $e;
        }

        $payload = [
            'message' => [
                'token' => $deviceToken,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'data' => $this->stringData($data),
                'android' => ['priority' => 'HIGH'],
                'apns' => [
                    'headers' => ['apns-priority' => '10'],
                    'payload' => ['aps' => ['sound' => 'default']],
                ],
            ],
        ];

        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->timeout(10)
            ->post(
                'https://fcm.googleapis.com/v1/projects/'.$this->projectId.'/messages:send',
                $payload,
            );

        if ($response->successful()) {
            return FcmSendResult::ok();
        }

        $status = (string) $response->json('error.status');
        $errorCode = (string) data_get($response->json(), 'error.details.0.errorCode');
        if ($status === 'NOT_FOUND' || $errorCode === 'UNREGISTERED') {
            return FcmSendResult::invalidToken();
        }

        Log::warning('FCM HTTP error', ['http' => $response->status(), 'status' => $status]);

        return FcmSendResult::failed('http_'.$response->status());
    }

    /** @param  array<string, mixed>  $data */
    private function stringData(array $data): array
    {
        $out = [];
        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }
            $out[(string) $key] = is_scalar($value) ? (string) $value : json_encode($value);
        }

        return $out;
    }

    private function accessToken(): string
    {
        $cacheKey = 'fcm.oauth.'.$this->projectId;
        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $now = time();
        $header = $this->b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $claims = $this->b64url(json_encode([
            'iss' => $this->clientEmail,
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => 'https://oauth2.googleapis.com/token',
            'iat' => $now,
            'exp' => $now + 3600,
        ], JSON_THROW_ON_ERROR));
        $unsigned = $header.'.'.$claims;
        $key = str_replace('\\n', "\n", $this->privateKey);
        if (! openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('FCM JWT sign failed.');
        }
        $jwt = $unsigned.'.'.$this->b64url($signature);

        $response = Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);
        if (! $response->successful()) {
            throw new \RuntimeException('FCM OAuth HTTP '.$response->status());
        }
        $token = (string) $response->json('access_token');
        $expires = max(60, ((int) $response->json('expires_in', 3600)) - 60);
        Cache::put($cacheKey, $token, $expires);

        return $token;
    }

    private function b64url(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
