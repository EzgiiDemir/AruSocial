<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\ModerationEvent;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Moderation\PenaltyLadder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Moderation review tools for administrators.
 *
 * Everything here is behind the `moderation.manage` permission (see
 * routes/api.php) — reading other students' violation history and lifting
 * bans are exactly the actions that must not be reachable by guessing a URL.
 */
class ModerationEventsController extends Controller
{
    use ApiResponds;

    private function toJson(ModerationEvent $e): array
    {
        return [
            'id' => $e->id,
            'userId' => (string) $e->user_id,
            'userName' => $e->user?->name,
            'contentType' => $e->content_type,
            'sourceFeature' => $e->source_feature,
            'contentId' => $e->content_id,
            'action' => $e->action,
            'flagged' => (bool) $e->flagged,
            'categories' => $e->categories ?? [],
            'categoryScores' => $e->category_scores ?? [],
            'decidedBy' => $e->decided_by,
            'strikeNumber' => $e->strike_number,
            'penalty' => $e->penalty,
            'bannedUntil' => $e->banned_until?->toIso8601String(),
            'provider' => $e->moderation_provider,
            'model' => $e->moderation_model,
            'excerpt' => $e->excerpt,
            'createdAt' => $e->created_at?->toIso8601String(),
        ];
    }

    /** Recent decisions, newest first, optionally filtered. */
    public function index(Request $request): JsonResponse
    {
        $query = ModerationEvent::with('user:id,name')->orderByDesc('created_at');

        if ($action = $request->query('action')) {
            $query->where('action', $action);
        }
        if ($userId = $request->query('userId')) {
            $query->where('user_id', $userId);
        }
        if ($feature = $request->query('sourceFeature')) {
            $query->where('source_feature', $feature);
        }

        $events = $query->limit(200)->get();

        return $this->ok($events->map(fn ($e) => $this->toJson($e)));
    }

    /** Accounts with at least one strike, with their current standing. */
    public function users(): JsonResponse
    {
        $ladder = new PenaltyLadder();
        $users = User::where('strikes', '>', 0)
            ->orderByDesc('last_violation_at')
            ->limit(200)
            ->get();

        return $this->ok($users->map(fn (User $u) => [
            'id' => (string) $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'strikes' => (int) $u->strikes,
            'moderationStatus' => $u->moderation_status ?? 'clear',
            'moderationReason' => $u->moderation_reason,
            'bannedUntil' => $u->banned_until?->toIso8601String(),
            'permanentlyBanned' => $u->banned_at !== null,
            'currentlyBanned' => $ladder->isCurrentlyBanned($u),
            'lastViolationAt' => $u->last_violation_at?->toIso8601String(),
            'nextPenalty' => $ladder->penaltyFor((int) $u->strikes + 1),
        ]));
    }

    /**
     * Reverses one strike. Used when a decision was wrong — the count goes
     * down and any ban that strike caused is lifted, because leaving the ban
     * in place would make the correction meaningless.
     */
    public function removeStrike(string $id): JsonResponse
    {
        $event = ModerationEvent::find($id);
        if (! $event) {
            return $this->fail(404, 'EVENT_NOT_FOUND', 'Moderation event not found.');
        }

        $user = User::find($event->user_id);
        if ($user && $user->strikes > 0) {
            $user->decrement('strikes');
            $user->update([
                'banned_until' => null,
                'moderation_status' => 'clear',
                'moderation_reason' => null,
            ]);
        }

        $event->update([
            'action' => 'reversed',
            'penalty' => null,
            'banned_until' => null,
        ]);

        AuditLogger::logAsCurrentUser('moderation', 'strike_removed',
            ($user?->name ?? $event->user_id).' — '.$event->source_feature);

        return $this->ok(['reversed' => true, 'strikes' => (int) ($user?->fresh()->strikes ?? 0)]);
    }

    /** Manual ban / unban, independent of the automatic ladder. */
    public function setBan(Request $request, string $userId): JsonResponse
    {
        $user = User::find($userId);
        if (! $user) {
            return $this->fail(404, 'USER_NOT_FOUND', 'User not found.');
        }

        $hours = (int) $request->input('hours', 0);
        $permanent = (bool) $request->boolean('permanent');
        $lift = (bool) $request->boolean('lift');

        if ($lift) {
            $user->update([
                'banned_at' => null,
                'banned_until' => null,
                'moderation_status' => 'clear',
            ]);
            AuditLogger::logAsCurrentUser('moderation', 'ban_lifted', $user->name);

            return $this->ok(['bannedUntil' => null, 'permanentlyBanned' => false]);
        }

        $user->update([
            'banned_at' => $permanent ? now() : null,
            'banned_until' => $permanent ? null : ($hours > 0 ? now()->addHours($hours) : null),
            'moderation_status' => 'banned',
        ]);

        AuditLogger::logAsCurrentUser('moderation', 'ban_applied',
            $user->name.' — '.($permanent ? 'permanent' : $hours.'h'));

        return $this->ok([
            'bannedUntil' => $user->fresh()->banned_until?->toIso8601String(),
            'permanentlyBanned' => $user->fresh()->banned_at !== null,
        ]);
    }

    /** The active ladder and thresholds, so admins can see current policy. */
    public function policy(): JsonResponse
    {
        return $this->ok([
            'penalties' => (array) config('services.moderation.penalties'),
            'thresholds' => (array) config('services.moderation.thresholds'),
            'model' => (string) config('services.moderation.model'),
            'providerConfigured' => trim((string) config('services.moderation.openai_key')) !== '',
            'failOpen' => (bool) config('services.moderation.fail_open'),
            'videoFrames' => (int) config('services.moderation.video_frames'),
        ]);
    }
}
