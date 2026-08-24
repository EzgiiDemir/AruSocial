<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\PaginatedListRequest;
use App\Models\AdminAuditLog;
use Illuminate\Http\JsonResponse;

class AuditLogController extends Controller
{
    use ApiResponds;

    public function index(PaginatedListRequest $request): JsonResponse
    {
        $query = AdminAuditLog::query()->orderByDesc('at')->orderByDesc('id');

        return $this->okPage($query, $request, fn ($r) => [
            'id' => $r->id,
            'actorName' => $r->actor_name,
            'action' => $r->action,
            'targetType' => $r->target_type,
            'targetLabel' => $r->target_label,
            'at' => $r->at?->toIso8601String(),
        ]);
    }
}
