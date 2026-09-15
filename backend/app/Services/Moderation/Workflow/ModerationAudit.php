<?php

namespace App\Services\Moderation\Workflow;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Append-only record of who changed what, when, and from what to what.
 *
 * Writes go through raw insert rather than an Eloquent model on purpose:
 * there is no model, so there is no `->update()` and no `->delete()` for
 * anyone to reach for. History that can be edited is not history.
 *
 * Context may hold ids, states and reasons. It must never hold a copy of
 * the media itself — an audit trail is not a second, less protected
 * archive of the content it describes.
 */
final class ModerationAudit
{
    public const ACTOR_MODERATOR = 'moderator';

    public const ACTOR_SYSTEM = 'system';

    public const ACTOR_USER = 'user';

    /** @param array<string, mixed> $context */
    public static function record(
        string $actorType,
        ?int $actorId,
        string $action,
        string $targetType,
        ?string $targetId,
        ?string $caseId = null,
        ?string $reason = null,
        ?string $previousState = null,
        ?string $newState = null,
        array $context = [],
    ): void {
        try {
            DB::table('moderation_audit_log')->insert([
                'id' => (string) Str::uuid(),
                'actor_type' => $actorType,
                'actor_id' => $actorId,
                'action' => $action,
                'target_type' => $targetType,
                'target_id' => $targetId,
                'moderation_case_id' => $caseId,
                'reason' => $reason,
                'previous_state' => $previousState,
                'new_state' => $newState,
                'context' => $context === [] ? null : json_encode($context),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // The action still happened; losing its record must not undo
            // it. But an unlogged moderator decision is one nobody can
            // answer for later, so this is never silent.
            Log::error('moderation.audit_write_failed', [
                'action' => $action,
                'target' => $targetType.':'.$targetId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
