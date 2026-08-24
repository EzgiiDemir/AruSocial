<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Support\Str;

// Admin-side audit trail (AuditLogStore on the mock client) — every
// successful admin create/update/delete. Distinct from ActivityLogger
// (a student's own real activity history in `activity_log`).
//
// Actor identity for admin mutations comes from the authenticated
// Sanctum user, not from the request body: a client can send
// `actorName` / `assignedBy` but that must not decide who the log
// says did the work. Call [logAsCurrentUser] from admin controllers.
// [log] stays for the few cases that are genuinely not "the signed-in
// admin" (automatic moderation strikes, a student submitting a form).
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

    public static function logAsCurrentUser(string $action, string $targetType, string $targetLabel): void
    {
        $user = request()->user();
        $name = $user instanceof User ? $user->name : 'system';
        self::log($name, $action, $targetType, $targetLabel);
    }
}
