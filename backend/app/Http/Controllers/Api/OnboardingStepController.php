<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Api\Concerns\ModeratesContent;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpsertOnboardingStepRequest;
use App\Models\OnboardingStep;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;

// Real replacement for the previously fully-hardcoded `onboardingSteps`
// const in `onboarding_config.dart` — admins can now edit the "First 30
// Days" checklist copy/ordering without touching Dart code. Per-user
// completion stays in `onboarding_progress` (ProfileController::onboarding),
// unaffected by this.
class OnboardingStepController extends Controller
{
    use ApiResponds, ModeratesContent;

    private function toJson(OnboardingStep $s): array
    {
        return [
            'id' => $s->id,
            'group' => $s->group_label,
            'title' => $s->title,
            'detail' => $s->detail,
            'actionKind' => $s->action_kind,
            'refId' => $s->ref_id,
            'sortOrder' => $s->sort_order,
        ];
    }

    public function index(): JsonResponse
    {
        $steps = OnboardingStep::where('active', true)
            ->orderBy('sort_order')
            ->get();

        return $this->ok($steps->map(fn ($s) => $this->toJson($s)));
    }

    // Admin editing view — includes inactive steps the public list hides.
    public function adminIndex(): JsonResponse
    {
        $steps = OnboardingStep::orderBy('sort_order')->get();

        return $this->ok($steps->map(fn ($s) => [
            ...$this->toJson($s),
            'active' => $s->active,
        ]));
    }

    public function upsert(UpsertOnboardingStepRequest $request): JsonResponse
    {
        if ($blocked = $this->moderationBlock($this->currentUser(), $this->moderationText($request->validated()), 'cms_onboarding', 'admin.onboarding.upsert')) {
            return $blocked;
        }
        $id = $request->input('id');
        $isNew = ! OnboardingStep::where('id', $id)->exists();
        $step = OnboardingStep::updateOrCreate(['id' => $id], [
            'group_label' => $request->input('groupLabel'),
            'title' => $request->input('title'),
            'detail' => $request->input('detail'),
            'action_kind' => $request->input('actionKind', 'info'),
            'ref_id' => $request->input('refId'),
            'sort_order' => (int) $request->input('sortOrder', 0),
            'active' => $request->boolean('active', true),
        ]);
        AuditLogger::logAsCurrentUser($isNew ? 'create' : 'update', 'onboarding_step', $step->title);

        return $this->ok($this->toJson($step), $isNew ? 201 : 200);
    }

    public function destroy(string $id): JsonResponse
    {
        $step = OnboardingStep::find($id);
        if ($step) {
            AuditLogger::logAsCurrentUser('delete', 'onboarding_step', $step->title);
            $step->delete();
        }

        return $this->ok(['deleted' => true]);
    }
}
