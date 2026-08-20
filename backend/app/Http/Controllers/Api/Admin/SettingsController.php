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
        AuditLogger::log($request->input('actorName', 'admin'), 'update', 'setting',
            'Görsel moderasyon API anahtarı '.($apiKey === '' ? 'temizlendi' : 'güncellendi'));

        return $this->ok(['configured' => $apiKey !== '']);
    }
}
