<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\RealtimeEvent;
use App\Models\RoleAssignment;
use App\Services\GranularPermissions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RealtimeController extends Controller
{
    use ApiResponds;

    private function canViewAdmin(): bool
    {
        $user = $this->currentUser();
        $assignment = RoleAssignment::find($user->email);
        $role = $assignment?->role ?? 'student';
        $allowed = ['superAdmin', 'contentEditor', 'clubManager', 'studentAffairs', 'careerStaff', 'moderator'];
        if (in_array($role, $allowed, true)) {
            return true;
        }

        return GranularPermissions::satisfiesBucket($assignment?->permissions ?? [], 'viewAdmin');
    }

    public function index(Request $request): JsonResponse
    {
        $me = $this->currentUser();
        $latest = (int) (RealtimeEvent::max('id') ?? 0);

        // First handshake omits `after` entirely so an empty log (cursor 0)
        // can still be followed by `?after=0` once live events appear.
        if (! $request->has('after')) {
            return $this->ok(['events' => [], 'cursor' => $latest]);
        }

        $after = max(0, (int) $request->query('after'));

        $canAdmin = $this->canViewAdmin();
        $userId = (string) $me->id;
        $rows = RealtimeEvent::query()
            ->where('id', '>', $after)
            ->where(function ($q) use ($userId, $canAdmin) {
                $q->where('audience', 'all')->orWhere('audience', $userId);
                if ($canAdmin) {
                    $q->orWhere('audience', 'admin');
                }
            })
            ->orderBy('id')
            ->limit(100)
            ->get();

        return $this->ok([
            'events' => $rows->map(fn (RealtimeEvent $e) => [
                'id' => $e->id,
                'type' => $e->type,
                'audience' => $e->audience,
                'entityType' => $e->entity_type,
                'entityId' => $e->entity_id,
                'actorId' => $e->actor_id,
                'payload' => $e->payload,
                'createdAt' => $e->created_at?->toIso8601String(),
            ]),
            'cursor' => $rows->isEmpty() ? $after : (int) $rows->last()->id,
        ]);
    }
}
