<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\ModerationEvent;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Moderation\AccountStanding;
use App\Services\Moderation\Workflow\AccountEnforcementPolicy;
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
            'points' => $e->points,
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

    /**
     * Accounts with a violation history, and where each one stands.
     *
     * `points` is what the ladder actually reads: active, unexpired
     * points. `strikes` is the lifetime count and is shown beside it
     * because the two answer different questions, namely how much trouble
     * this account is in right now, and how often this has happened.
     */
    public function users(): JsonResponse
    {
        $standing = new AccountStanding;
        $policy = new AccountEnforcementPolicy;

        $users = User::where('strikes', '>', 0)
            ->orderByDesc('last_violation_at')
            ->limit(200)
            ->get();

        return $this->ok($users->map(function (User $u) use ($standing, $policy) {
            $current = $policy->standing($u);

            return [
                'id' => (string) $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'strikes' => (int) $u->strikes,
                'points' => $current['points'],
                'standing' => ['action' => $current['action'], 'hours' => $current['hours']],
                'moderationStatus' => $u->moderation_status ?? 'clear',
                'moderationReason' => $u->moderation_reason,
                'bannedUntil' => $u->banned_until?->toIso8601String(),
                'postingRestrictedUntil' => $u->posting_restricted_until?->toIso8601String(),
                'permanentlyBanned' => $u->banned_at !== null,
                'currentlyBanned' => $standing->isCurrentlyBanned($u),
                'postingRestricted' => $standing->isPostingRestricted($u),
                'lastViolationAt' => $u->last_violation_at?->toIso8601String(),
                // What one more minor violation would cost from here.
                'nextPenalty' => $policy->consequenceFor($current['points'] + 1),
            ];
        }));
    }

    /**
     * Reverses one decision. Used when it was wrong.
     *
     * The violation it charged is withdrawn, not deleted, so the record of
     * the reversal survives; the account standing is then recomputed from
     * the points that remain. Recomputing rather than blanket-clearing
     * matters once several violations can be in play: clearing the lock
     * outright would forgive the other violations that helped earn it, and
     * leaving it in place would make the correction meaningless.
     */
    public function removeStrike(string $id): JsonResponse
    {
        $event = ModerationEvent::find($id);
        if (! $event) {
            return $this->fail(404, 'EVENT_NOT_FOUND', 'Moderation event not found.');
        }

        $policy = new AccountEnforcementPolicy;
        $user = User::find($event->user_id);

        if ($user) {
            if ($event->submission_hash !== null) {
                $policy->withdraw($user, 'content:'.$event->submission_hash);
            }
            if ($user->strikes > 0) {
                $user->decrement('strikes');
            }
            $user->refresh();
            // Recompute even when no violation row matched: an event from
            // before the unified ladder has no row to withdraw, and the
            // reversal still has to lift what it caused.
            $policy->recompute($user);
        }

        $event->update([
            'action' => 'reversed',
            'penalty' => null,
            'points' => null,
            'banned_until' => null,
        ]);

        AuditLogger::logAsCurrentUser('moderation', 'strike_removed',
            ($user?->name ?? $event->user_id).' — '.$event->source_feature);

        $fresh = $user?->fresh();

        return $this->ok([
            'reversed' => true,
            'strikes' => (int) ($fresh?->strikes ?? 0),
            'points' => $fresh !== null ? $policy->activePoints($fresh) : 0,
            'bannedUntil' => $fresh?->banned_until?->toIso8601String(),
            'postingRestrictedUntil' => $fresh?->posting_restricted_until?->toIso8601String(),
        ]);
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
            // One ladder, shared by every path that can charge an account.
            'bands' => (array) config('moderation.enforcement.bands'),
            'ladder' => (array) config('moderation.enforcement.ladder'),
            'severity' => (array) config('moderation.enforcement.severity'),
            'enforcementEnabled' => (bool) config('moderation.enforcement.enabled', true),
            'thresholds' => (array) config('services.moderation.thresholds'),
            'model' => (string) config('services.moderation.model'),
            'providerConfigured' => trim((string) config('services.moderation.openai_key')) !== '',
            'failClosed' => (bool) config('services.moderation.fail_closed', true),
        ]);
    }
}
