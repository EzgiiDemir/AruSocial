<?php

namespace App\Filament\Concerns;

use App\Models\User;
use App\Services\GranularPermissions;

/**
 * Authorisation for the AICAD Knowledge screens (aliases, knowledge sources,
 * crawled pages, Search Playground).
 *
 * They change or reveal what the assistant reads, which is the `ai.prompt`
 * resource: `read` to look, `manage_settings` to change. Platform admins
 * hold both; nobody else does unless granted.
 */
trait AuthorizesAicadKnowledge
{
    protected static function aicadMay(string $action): bool
    {
        $user = auth()->user();

        return $user instanceof User && GranularPermissions::allows($user, 'ai.prompt.'.$action);
    }

    public static function aicadCanRead(): bool
    {
        return static::aicadMay('read');
    }

    public static function aicadCanManage(): bool
    {
        return static::aicadMay('manage_settings');
    }
}
