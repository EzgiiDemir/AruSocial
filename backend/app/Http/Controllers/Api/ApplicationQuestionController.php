<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\ApplicationQuestion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

// Admin-managed question schema behind the two-stage apply flow (see
// ParticipationApplicationController/Service) — never a hardcoded field
// list per category. `index` is what the app itself reads before showing
// a Preview form; `adminIndex`/`upsert`/`destroy` are the management
// surface (Admin Panel → Applications → Sorular).
class ApplicationQuestionController extends Controller
{
    use ApiResponds;

    public function index(Request $request): JsonResponse
    {
        $targetType = $request->query('targetType');
        $stage = $request->query('stage');
        if (! $targetType || ! in_array($stage, ['preview', 'detail'], true)) {
            return $this->fail(400, 'VALIDATION', 'targetType and a valid stage (preview|detail) are required.');
        }

        $questions = ApplicationQuestion::where('target_type', $targetType)
            ->where('stage', $stage)
            ->where('active', true)
            ->orderBy('sort_order')
            ->get();

        return $this->ok($questions->map->toApiArray());
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $query = ApplicationQuestion::query()->orderBy('target_type')->orderBy('stage')->orderBy('sort_order');
        if ($targetType = $request->query('targetType')) {
            $query->where('target_type', $targetType);
        }
        if ($stage = $request->query('stage')) {
            $query->where('stage', $stage);
        }

        return $this->ok($query->get()->map->toApiArray());
    }

    public function upsert(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['nullable', 'string'],
            'targetType' => ['required', Rule::in(['club', 'sport', 'service', 'career', 'community', 'help', 'event'])],
            'stage' => ['required', Rule::in(['preview', 'detail'])],
            'type' => ['required', Rule::in(['text', 'textarea', 'single_choice', 'multiple_choice', 'dropdown', 'date', 'number', 'file', 'checkbox'])],
            'label' => ['required', 'string', 'max:255'],
            'helpText' => ['nullable', 'string'],
            'options' => ['nullable', 'array'],
            'required' => ['boolean'],
            'sortOrder' => ['integer'],
            'active' => ['boolean'],
        ]);

        $id = $data['id'] ?? 'aq-'.Str::uuid();
        $question = ApplicationQuestion::updateOrCreate(['id' => $id], [
            'target_type' => $data['targetType'],
            'stage' => $data['stage'],
            'type' => $data['type'],
            'label' => $data['label'],
            'help_text' => $data['helpText'] ?? null,
            'options' => $data['options'] ?? [],
            'required' => $data['required'] ?? false,
            'sort_order' => $data['sortOrder'] ?? 0,
            'active' => $data['active'] ?? true,
        ]);

        return $this->ok($question->toApiArray(), $request->input('id') ? 200 : 201);
    }

    public function destroy(string $id): JsonResponse
    {
        ApplicationQuestion::where('id', $id)->delete();

        return $this->ok(['deleted' => true]);
    }
}
