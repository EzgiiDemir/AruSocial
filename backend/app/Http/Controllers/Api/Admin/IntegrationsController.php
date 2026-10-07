<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\IntegrationState;
use App\Services\AuditLogger;
use App\Services\Integrations\IntegrationRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read and operate the integration list.
 *
 * Authorization is the route's `permission:` middleware (see routes/api.php):
 * `system.integration.read` to look, `system.integration.manage_settings` to
 * test or switch one off. Nothing here re-checks it — one place to get right.
 *
 * No endpoint in this controller accepts or returns a credential. Configuring
 * an integration is either a deploy (env) or the existing site-settings
 * endpoint (Entra/WordPress), which already stores secrets write-only.
 */
class IntegrationsController extends Controller
{
    use ApiResponds;

    public function __construct(private readonly IntegrationRegistry $registry) {}

    public function index(): JsonResponse
    {
        return $this->ok($this->registry->all());
    }

    public function test(string $key): JsonResponse
    {
        $definition = $this->registry->find($key);
        if ($definition === null) {
            return $this->fail(404, 'INTEGRATION_NOT_FOUND', 'Böyle bir entegrasyon yok.');
        }

        $user = $this->currentUser();
        $result = $this->registry->test($definition, $user->email);

        AuditLogger::logAsCurrentUser(
            'test',
            'integration',
            sprintf('%s bağlantı testi: %s', $definition->name, $result->skipped
                ? 'yapılandırılmamış'
                : ($result->ok ? 'başarılı' : 'başarısız')),
        );

        $state = IntegrationState::find($definition->key);

        return $this->ok([
            'ok' => $result->ok,
            'skipped' => $result->skipped,
            'message' => $result->message,
            'integration' => $this->registry->present($definition, $state),
        ]);
    }

    public function setEnabled(Request $request, string $key): JsonResponse
    {
        $definition = $this->registry->find($key);
        if ($definition === null) {
            return $this->fail(404, 'INTEGRATION_NOT_FOUND', 'Böyle bir entegrasyon yok.');
        }

        $validated = $request->validate(['enabled' => ['required', 'boolean']]);
        $enabled = (bool) $validated['enabled'];

        $user = $this->currentUser();
        $state = $this->registry->setEnabled($definition, $enabled, $user->email);

        AuditLogger::logAsCurrentUser(
            'update',
            'integration',
            sprintf('%s entegrasyonu %s', $definition->name, $enabled ? 'etkinleştirildi' : 'devre dışı bırakıldı'),
        );

        return $this->ok($this->registry->present($definition, $state));
    }
}
