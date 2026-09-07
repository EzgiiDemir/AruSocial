<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\AchievementDefinition;
use App\Models\UserAchievement;
use Illuminate\Http\JsonResponse;

// Read-only achievement state for the signed-in user. Unlock happens only
// via AchievementEvaluator after real domain events — there is no claim API.
class AchievementController extends Controller
{
    use ApiResponds;

    public function index(): JsonResponse
    {
        $me = $this->currentUser();
        $unlocked = UserAchievement::where('user_id', $me->id)
            ->get()
            ->keyBy('achievement_id');

        $items = AchievementDefinition::orderBy('sort_order')->get()->map(function ($def) use ($unlocked) {
            $row = $unlocked->get($def->id);

            return [
                'id' => $def->id,
                'title' => $def->title,
                'subtitle' => $def->subtitle,
                'triggerKind' => $def->trigger_kind,
                'threshold' => (int) $def->threshold,
                'unlocked' => $row !== null,
                'unlockedAt' => $row?->unlocked_at?->toIso8601String(),
            ];
        });

        return $this->ok($items);
    }
}
