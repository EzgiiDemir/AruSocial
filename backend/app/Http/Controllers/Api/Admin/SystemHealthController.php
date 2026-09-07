<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\AppSetting;
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
            'aiConfigured' => (bool) config('services.groq.key'),
            'broadcastingConfigured' => (bool) config('broadcasting.connections.reverb.key')
                && config('broadcasting.default') === 'reverb',
        ]);
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
