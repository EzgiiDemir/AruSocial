<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\AppSetting;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Server-side-only settings an admin manages from the panel but that the
// client never reads back — starting with the image-moderation API key
// (docs/EKSIKLER.md §26). Deliberately never returns the raw key: only
// whether one is configured, same "write-only from the client's
// perspective" shape a real secrets manager would have.
class SettingsController extends Controller
{
    use ApiResponds;

    public function moderation(): JsonResponse
    {
        return $this->ok(['configured' => (bool) AppSetting::getValue('moderation.apiKey')]);
    }

    public function setModeration(Request $request): JsonResponse
    {
        $apiKey = $request->input('apiKey', '');
        AppSetting::setValue('moderation.apiKey', $apiKey === '' ? null : $apiKey);
        AuditLogger::log($this->currentUser()->name, 'update', 'setting',
            'Görsel moderasyon API anahtarı '.($apiKey === '' ? 'temizlendi' : 'güncellendi'));

        return $this->ok(['configured' => $apiKey !== '']);
    }

    // Real, admin-configurable check-in proximity threshold — the actual
    // enforcement lives in CheckinController::store(), which reads the
    // same AppSetting key server-side rather than trusting anything the
    // client claims about its own distance.
    public function checkinRadius(): JsonResponse
    {
        return $this->ok(['radiusMeters' => (int) (AppSetting::getValue('checkin.radiusMeters') ?? 150)]);
    }

    public function setCheckinRadius(Request $request): JsonResponse
    {
        $radius = max(10, min(5000, (int) $request->input('radiusMeters', 150)));
        AppSetting::setValue('checkin.radiusMeters', (string) $radius);
        AuditLogger::log($this->currentUser()->name, 'update', 'setting',
            "Check-in mesafe eşiği {$radius}m olarak güncellendi");

        return $this->ok(['radiusMeters' => $radius]);
    }

    // Real authentication settings (docs/EKSIKLER.md admin §13): allowed
    // sign-in email domains and the Entra Client Secret. The domain list
    // is genuinely enforced — AuthController::session() reads this same
    // key instead of a hardcoded "@arucad.edu.tr" check. The Client
    // Secret follows the exact same write-only shape as the image-
    // moderation key above (never echoed back to any client) — mobile
    // sign-in itself uses PKCE and needs no secret at all
    // (frontend/lib/core/l10n/admin_strings.dart's own admin_entra_desc
    // explains why), but a real Entra *backend* token exchange (e.g. for
    // a future server-side flow) would need one stored server-side only,
    // which is what this is for — not sent to any client, ever.
    public function auth(): JsonResponse
    {
        return $this->ok([
            'allowedDomains' => $this->allowedDomains(),
            'entraClientSecretConfigured' => (bool) AppSetting::getValue('auth.entraClientSecret'),
        ]);
    }

    public function setAuth(Request $request): JsonResponse
    {
        if ($request->has('allowedDomains')) {
            $domains = collect((array) $request->input('allowedDomains'))
                ->map(fn ($d) => strtolower(trim((string) $d)))
                ->filter(fn ($d) => $d !== '' && str_starts_with($d, '@'))
                ->unique()
                ->values();
            if ($domains->isEmpty()) {
                return $this->fail(400, 'VALIDATION', 'At least one allowed domain (e.g. "@arucad.edu.tr") is required.');
            }
            AppSetting::setValue('auth.allowedDomains', $domains->implode(','));
            AuditLogger::log($this->currentUser()->name, 'update', 'setting',
                'İzinli e-posta domainleri güncellendi: '.$domains->implode(', '));
        }

        if ($request->has('entraClientSecret')) {
            $secret = (string) $request->input('entraClientSecret', '');
            AppSetting::setValue('auth.entraClientSecret', $secret === '' ? null : $secret);
            AuditLogger::log($this->currentUser()->name, 'update', 'setting',
                'Entra Client Secret '.($secret === '' ? 'temizlendi' : 'güncellendi'));
        }

        return $this->auth();
    }

    /** @return string[] */
    public static function allowedDomains(): array
    {
        $raw = AppSetting::getValue('auth.allowedDomains');
        if (! $raw) {
            return ['@arucad.edu.tr'];
        }

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    // Real, admin-configurable default check-in XP — CheckinController
    // reads this same key rather than a hardcoded amount, same pattern as
    // the check-in radius above.
    public function xp(): JsonResponse
    {
        return $this->ok(['checkinXp' => (int) (AppSetting::getValue('xp.checkinAmount') ?? 10)]);
    }

    public function setXp(Request $request): JsonResponse
    {
        $amount = max(0, min(500, (int) $request->input('checkinXp', 10)));
        AppSetting::setValue('xp.checkinAmount', (string) $amount);
        AuditLogger::log($this->currentUser()->name, 'update', 'setting',
            "Check-in XP miktarı {$amount} olarak güncellendi");

        return $this->ok(['checkinXp' => $amount]);
    }
}
