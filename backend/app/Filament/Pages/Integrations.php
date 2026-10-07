<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Services\Ai\AiProviderManager;
use App\Services\Ai\AiTelemetry;
use App\Services\AuditLogger;
use App\Services\GranularPermissions;
use App\Services\Integrations\IntegrationRegistry;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use UnitEnum;

/**
 * Integrations — what this product is wired to, and whether it is working.
 *
 * A read-and-operate page, not a credential editor. Credentials arrive by
 * deploy (env) or through the existing site-settings screen, which already
 * stores them write-only; putting a second edit path here would mean a
 * second place that could leak them.
 *
 * All of the thinking lives in [IntegrationRegistry] so this page and the
 * JSON API can never disagree about whether something is connected.
 */
class Integrations extends Page
{
    protected string $view = 'filament.pages.integrations';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static string|UnitEnum|null $navigationGroup = 'panel.groups.system';

    protected static ?int $navigationSort = 30;

    /** @var list<array<string, mixed>> */
    public array $rows = [];

    public static function getNavigationLabel(): string
    {
        return __('panel.integrations.title');
    }

    public function getTitle(): string
    {
        return __('panel.integrations.title');
    }

    public function getSubheading(): ?string
    {
        // Surface the LIVE AI mode — reflects the circuit breaker, so it shows
        // "Local AI Fallback" or "Knowledge-Only" during a Groq outage.
        $mode = app(AiProviderManager::class)->liveModeLabel();

        // Compact, secret-free usage counters so staff can see (and reduce)
        // Groq load: requests, failures, cache hits, fallbacks.
        $m = app(AiTelemetry::class)->snapshot();
        $stats = sprintf(
            'Groq çağrı: %d · hata: %d · önbellek isabeti: %d · yerel yedek: %d · bilgi-tabanı: %d',
            $m['groq_calls'], $m['groq_failures'], $m['cache_hits'], $m['local_fallbacks'], $m['knowledge_only'],
        );

        return __('panel.integrations.subheading').' · '.__('panel.integrations.ai_mode').': '.$mode.' · '.$stats;
    }

    /**
     * Same permission the JSON API uses. Filament asks this for both the
     * sidebar entry and direct URL access, so there is no "hidden but
     * reachable" state.
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && GranularPermissions::allows($user, 'system.integration.read');
    }

    public function mount(): void
    {
        $this->refreshRows();
    }

    public function refreshRows(): void
    {
        $this->rows = app(IntegrationRegistry::class)->all();
    }

    /** Writing needs the stricter grant; reading this page does not imply it. */
    public function canManage(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && GranularPermissions::allows($user, 'system.integration.manage_settings');
    }

    public function test(string $key): void
    {
        if (! $this->canManage()) {
            $this->denied();

            return;
        }

        $registry = app(IntegrationRegistry::class);
        $definition = $registry->find($key);
        if ($definition === null) {
            return;
        }

        $user = auth()->user();
        $result = $registry->test($definition, $user instanceof User ? $user->email : null);

        AuditLogger::logAsCurrentUser(
            'test',
            'integration',
            sprintf('%s bağlantı testi: %s', $definition->name, $result->skipped
                ? 'yapılandırılmamış'
                : ($result->ok ? 'başarılı' : 'başarısız')),
        );

        $notification = Notification::make()->title($definition->name)->body($result->message);
        // A skipped test is information, not a failure — colouring it red
        // would tell an operator mid-setup that something is broken.
        $result->ok
            ? $notification->success()
            : ($result->skipped ? $notification->warning() : $notification->danger());
        $notification->send();

        $this->refreshRows();
    }

    public function toggle(string $key, bool $enabled): void
    {
        if (! $this->canManage()) {
            $this->denied();

            return;
        }

        $registry = app(IntegrationRegistry::class);
        $definition = $registry->find($key);
        if ($definition === null) {
            return;
        }

        $user = auth()->user();
        $registry->setEnabled($definition, $enabled, $user instanceof User ? $user->email : null);

        AuditLogger::logAsCurrentUser(
            'update',
            'integration',
            sprintf('%s entegrasyonu %s', $definition->name, $enabled ? 'etkinleştirildi' : 'devre dışı bırakıldı'),
        );

        Notification::make()
            ->title($definition->name)
            ->body(__($enabled ? 'panel.integrations.enabled_done' : 'panel.integrations.disabled_done'))
            ->success()
            ->send();

        $this->refreshRows();
    }

    private function denied(): void
    {
        Notification::make()
            ->title(__('panel.integrations.denied'))
            ->danger()
            ->send();
    }

    /** @return array{label: string, color: string} */
    public function statusBadge(string $status): array
    {
        return match ($status) {
            IntegrationRegistry::STATUS_CONNECTED => ['label' => __('panel.integrations.status.connected'), 'color' => 'success'],
            IntegrationRegistry::STATUS_ERROR => ['label' => __('panel.integrations.status.error'), 'color' => 'danger'],
            IntegrationRegistry::STATUS_DISABLED => ['label' => __('panel.integrations.status.disabled'), 'color' => 'gray'],
            default => ['label' => __('panel.integrations.status.not_configured'), 'color' => 'warning'],
        };
    }

    /** Never used for a credential — only for timestamps. */
    public function human(?string $iso): string
    {
        if ($iso === null) {
            return __('panel.integrations.never');
        }

        return Carbon::parse($iso)->diffForHumans();
    }
}
