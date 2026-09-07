<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateSiteSettingsRequest;
use App\Models\AppSetting;
use App\Services\AuditLogger;
use App\Services\ImageModerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Server-side-only site settings. Media moderation uses ARUCAD's local
// review queue; no vendor moderation key is stored or sent from this API.
class SettingsController extends Controller
{
    use ApiResponds;

    public function moderation(): JsonResponse
    {
        return $this->ok([
            'configured' => true,
            'mode' => 'local_review',
            'semanticClassifierConfigured' => ImageModerationService::semanticClassifierConfigured(),
        ]);
    }

    public function setModeration(Request $request): JsonResponse
    {
        AuditLogger::logAsCurrentUser('update', 'setting', 'Yerel medya inceleme politikası doğrulandı');

        return $this->ok([
            'configured' => true,
            'mode' => 'local_review',
            'semanticClassifierConfigured' => ImageModerationService::semanticClassifierConfigured(),
        ]);
    }

    // Public Entra client config + WordPress site URL. The WP token is the
    // same write-only shape as the moderation API key: stored in
    // app_settings, usable server-side, never returned in GET/POST bodies.
    public function site(): JsonResponse
    {
        return $this->ok($this->sitePayload());
    }

    public function setSite(UpdateSiteSettingsRequest $request): JsonResponse
    {
        if (is_array($request->input('entra'))) {
            $entra = $request->input('entra');
            if (array_key_exists('tenantId', $entra)) {
                AppSetting::setValue('entra.tenantId', $this->nullableString($entra['tenantId'] ?? null));
            }
            if (array_key_exists('clientId', $entra)) {
                AppSetting::setValue('entra.clientId', $this->nullableString($entra['clientId'] ?? null));
            }
            if (array_key_exists('redirectUri', $entra)) {
                AppSetting::setValue('entra.redirectUri', $this->nullableString($entra['redirectUri'] ?? null));
            }
        }

        $tokenCleared = false;
        $tokenUpdated = false;
        $wpTouched = false;
        if (is_array($request->input('wordpress'))) {
            $wp = $request->input('wordpress');
            if (array_key_exists('siteUrl', $wp)) {
                AppSetting::setValue('wordpress.siteUrl', $this->nullableString($wp['siteUrl'] ?? null));
                $wpTouched = true;
            }
            if (array_key_exists('apiToken', $wp)) {
                $token = $this->nullableString($wp['apiToken'] ?? null);
                AppSetting::setValue('wordpress.apiToken', $token);
                $tokenCleared = $token === null;
                $tokenUpdated = $token !== null;
                $wpTouched = true;
            }
        }

        $label = 'Site ayarları güncellendi';
        if ($tokenCleared) {
            $label = 'WordPress API token temizlendi';
        } elseif ($tokenUpdated) {
            $label = 'WordPress API token güncellendi';
        } elseif ($wpTouched) {
            $label = 'WordPress site ayarları güncellendi';
        } elseif (is_array($request->input('entra'))) {
            $label = 'Entra site ayarları güncellendi';
        }

        AuditLogger::logAsCurrentUser('update', 'setting', $label);

        return $this->ok($this->sitePayload());
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /** @return array{entra: array{tenantId: string, clientId: string, redirectUri: string}, wordpress: array{siteUrl: string, apiTokenConfigured: bool}} */
    private function sitePayload(): array
    {
        return [
            'entra' => [
                'tenantId' => AppSetting::getValue('entra.tenantId') ?? '',
                'clientId' => AppSetting::getValue('entra.clientId') ?? '',
                'redirectUri' => AppSetting::getValue('entra.redirectUri') ?? '',
            ],
            'wordpress' => [
                'siteUrl' => AppSetting::getValue('wordpress.siteUrl') ?? '',
                'apiTokenConfigured' => (bool) AppSetting::getValue('wordpress.apiToken'),
            ],
        ];
    }
}
