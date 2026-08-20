<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use Illuminate\Http\JsonResponse;

class AuditLogController extends Controller
{
    use ApiResponds;

    public function index(): JsonResponse
    {
        $rows = AdminAuditLog::orderByDesc('at')->limit(200)->get();

        return $this->ok($rows->map(fn ($r) => [
            'id' => $r->id,
            'actorName' => $r->actor_name,
            'action' => $r->action,
            'targetType' => $r->target_type,
            'targetLabel' => $r->target_label,
            'at' => $r->at?->toIso8601String(),
        ]));
    }
}
