<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Api\Concerns\ModeratesContent;
use App\Http\Controllers\Controller;
use App\Http\Requests\SetOnboardingStepRequest;
use App\Http\Requests\UpdateProfileBioRequest;
use App\Http\Requests\UpdateUserSettingsRequest;
use App\Models\ActivityLog;
use App\Models\Checkin;
use App\Models\EventJoin;
use App\Models\OnboardingProgress;
use App\Models\Quest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class ProfileController extends Controller
{
    use ApiResponds, ModeratesContent;

    // Matches CampusUserDto.fromJson in lib/core/network/campus_dtos.dart —
    // see User::toApiArray(), shared with the login response.
    public function me(): JsonResponse
    {
        return $this->ok($this->currentUser()->toApiArray());
    }

    // Replaces the old client-only ProfileBioStore (SharedPreferences)
    // overlay — these six fields are now real, shared columns on `users`,
    // scoped to currentUser() so a client can never edit anyone else's
    // profile by supplying a different id. Absent keys leave the existing
    // value untouched (a partial save from one field of the edit form
    // doesn't blank out the others); an explicit null/[] does clear it.
    public function updateBio(UpdateProfileBioRequest $request): JsonResponse
    {
        $me = $this->currentUser();
        $me->fill($request->only(['department', 'year', 'university', 'clubs', 'achievements', 'projects']));
        if ($request->exists('avatarUrl')) {
            $me->avatar_url = $request->input('avatarUrl');
        }

        // A profile is public surface too: bios, club/achievement/project
        // lists and the avatar are all visible to other students, so they
        // go through the same gate as a post rather than being trusted.
        $profileText = $this->profileFreeText($me);
        if ($blocked = $this->moderationBlock(
            $me, $profileText, 'profile', 'profile.updateBio',
            $this->moderatableImages($request->input('avatarUrl')),
        )) {
            return $blocked;
        }

        $me->save();

        return $this->ok($me->toApiArray());
    }

    /**
     * Every free-text field a student controls on their profile, joined so
     * one moderation call covers the whole submission.
     */
    private function profileFreeText(User $me): string
    {
        $parts = [$me->department, $me->year, $me->university];
        foreach ([$me->clubs, $me->achievements, $me->projects] as $list) {
            if (is_array($list)) {
                $parts = array_merge($parts, $list);
            }
        }

        return trim(implode("\n", array_filter(array_map(
            fn ($v) => is_string($v) ? trim($v) : null,
            $parts,
        ))));
    }

    public function settings(): JsonResponse
    {
        return $this->ok($this->settingsPayload($this->currentUser()));
    }

    public function updateSettings(UpdateUserSettingsRequest $request): JsonResponse
    {
        $me = $this->currentUser();
        if ($request->exists('locationVisibility')) {
            $me->location_visibility = $request->input('locationVisibility');
        }
        if ($request->exists('nearbyDiscoverable')) {
            $me->nearby_discoverable = $request->boolean('nearbyDiscoverable');
        }
        if ($request->exists('checkInVisible')) {
            $me->check_in_visible = $request->boolean('checkInVisible');
        }
        if ($request->exists('personalization')) {
            $me->personalization = $request->boolean('personalization');
        }
        if ($request->exists('isPrivateProfile')) {
            $me->is_private_profile = $request->boolean('isPrivateProfile');
        }
        if ($request->exists('preferredLanguage')) {
            $me->preferred_language = strtoupper((string) $request->input('preferredLanguage'));
        }
        $me->save();

        return $this->ok($this->settingsPayload($me));
    }

    private function settingsPayload(User $u): array
    {
        $lang = strtoupper((string) ($u->preferred_language ?: 'TR'));
        if (! in_array($lang, ['TR', 'EN', 'RU'], true)) {
            $lang = 'TR';
        }

        return [
            'locationVisibility' => $u->location_visibility ?: 'ghost',
            'nearbyDiscoverable' => (bool) $u->nearby_discoverable,
            'checkInVisible' => $u->check_in_visible ?? true,
            'personalization' => $u->personalization ?? true,
            'isPrivateProfile' => (bool) $u->is_private_profile,
            'preferredLanguage' => $lang,
        ];
    }

    // Matches QuestDto.fromJson. `progress` is computed from real events
    // when `kind` is set (`distinct_checkins` / `event_joins`); `static`
    // rows keep the stored column so leftover seed data still round-trips.
    public function quests(): JsonResponse
    {
        $u = $this->currentUser();
        $counts = $this->questCountsFor($u->id);
        $quests = Quest::where('user_id', $u->id)->get()->map(fn ($q) => [
            'id' => $q->id,
            'title' => $q->title,
            'subtitle' => $q->subtitle,
            'progress' => $this->questProgress($q, $counts),
            'target' => $q->target,
            'reward' => $q->reward,
        ]);

        return $this->ok($quests);
    }

    private function questCountsFor(int $userId): array
    {
        return [
            'distinct_checkins' => (int) Checkin::where('user_id', $userId)
                ->selectRaw('count(distinct place_id) as aggregate')
                ->value('aggregate'),
            'event_joins' => EventJoin::where('user_id', $userId)->count(),
        ];
    }

    private function questProgress(Quest $q, array $counts): int
    {
        $raw = match ($q->kind) {
            'distinct_checkins' => $counts['distinct_checkins'],
            'event_joins' => $counts['event_joins'],
            default => (int) $q->progress,
        };

        return min((int) $q->target, max(0, $raw));
    }

    // Matches RestCampusRepository.getMyActivity()'s manual parse.
    public function activity(): JsonResponse
    {
        $u = $this->currentUser();
        $items = ActivityLog::where('user_id', $u->id)
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'kind' => $a->kind,
                'title' => $a->title,
                'subtitle' => $a->subtitle,
                'meta' => $a->meta,
                'createdAt' => $a->created_at?->toIso8601String(),
                'xp' => $this->activityXp($a),
            ]);

        return $this->ok($items);
    }

    // Product rule (DC-9): `users.xp` is the lifetime total and is never
    // reset. Yearly XP on the Activity tab is the sum of XP-bearing
    // activity in that calendar year — check-in is always +10, event join
    // is `+$event->xp` parsed from the subtitle written at award time.
    // There is no `xp_transactions` ledger (that table was reverted);
    // activity_log + these two fields are the yearly breakdown.
    private function activityXp(ActivityLog $a): int
    {
        if ($a->kind === 'checkIn') {
            return 10;
        }
        if ($a->kind === 'eventJoin' && preg_match('/\+(\d+)\s*XP/', (string) $a->subtitle, $m)) {
            return (int) $m[1];
        }

        return 0;
    }

    // "First 30 Days" checklist — completion state only (the checklist
    // content itself is static product copy in the Flutter app, see the
    // onboarding_progress migration's doc comment). Eligibility is server
    // computed: prefer users.year (1st / hazırlık); otherwise only brand-new
    // accounts (≤14 days). No invented isFirstYear column.
    public function onboarding(): JsonResponse
    {
        $me = $this->currentUser();
        $done = OnboardingProgress::where('user_id', $me->id)->pluck('step_id');

        return $this->ok([
            'done' => $done,
            'startedAt' => $me->created_at?->toIso8601String(),
            'eligible' => $this->onboardingEligible($me),
        ]);
    }

    private function onboardingEligible(User $me): bool
    {
        $year = mb_strtolower(trim((string) ($me->year ?? '')));
        if ($year !== '') {
            if (preg_match('/^(1|1\.|1st|birinci|hazırlık|hazirlik|prep|preparatory)/u', $year) === 1) {
                return true;
            }
            if (preg_match('/^[2-8]/u', $year) === 1) {
                return false;
            }
        }

        return $me->created_at !== null
            && $me->created_at->greaterThanOrEqualTo(now()->subDays(14));
    }

    public function setOnboardingStep(SetOnboardingStepRequest $request, string $stepId): JsonResponse
    {
        $me = $this->currentUser();

        if ($request->boolean('completed')) {
            OnboardingProgress::firstOrCreate(
                ['user_id' => $me->id, 'step_id' => $stepId],
                ['completed_at' => now()],
            );
        } else {
            OnboardingProgress::where('user_id', $me->id)->where('step_id', $stepId)->delete();
        }

        return $this->ok(['completed' => $request->boolean('completed')]);
    }
}
