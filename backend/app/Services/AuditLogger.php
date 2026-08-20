<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use Illuminate\Support\Str;

// Admin-side audit trail (AuditLogStore) — every admin create/update/
// delete, distinct from ActivityLogger (a student's own real activity
// history in `activity_log`).
class AuditLogger
{
    public static function log(string $actorName, string $action, string $targetType, string $targetLabel): void
    {
        AdminAuditLog::create([
            'id' => 'audit-'.Str::uuid(),
            'actor_name' => $actorName,
            'action' => $action,
            'target_type' => $targetType,
            'target_label' => $targetLabel,
            'at' => now(),
        ]);
    }
}
