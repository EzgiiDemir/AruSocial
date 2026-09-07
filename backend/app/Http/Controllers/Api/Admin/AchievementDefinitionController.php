<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpsertAchievementDefinitionRequest;
use App\Models\AchievementDefinition;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class AchievementDefinitionController extends Controller
{
    use ApiResponds;

    public function index(): JsonResponse
    {
        $rows = AchievementDefinition::orderBy('sort_order')->orderBy('title')->get()
            ->map(fn (AchievementDefinition $d) => [
                'id' => $d->id,
                'title' => $d->title,
                'subtitle' => $d->subtitle,
                'triggerKind' => $d->trigger_kind,
                'threshold' => (int) $d->threshold,
                'sortOrder' => (int) $d->sort_order,
                'active' => (bool) ($d->active ?? true),
            ]);

        return $this->ok($rows);
    }

    public function upsert(UpsertAchievementDefinitionRequest $request): JsonResponse
    {
        $id = $request->input('id') ?: 'ach-'.Str::uuid();
        $isNew = ! AchievementDefinition::where('id', $id)->exists();
        $def = AchievementDefinition::updateOrCreate(['id' => $id], [
            'title' => $request->input('title'),
            'subtitle' => $request->input('subtitle', ''),
            'trigger_kind' => $request->input('triggerKind'),
            'threshold' => (int) $request->input('threshold', 1),
            'sort_order' => (int) $request->input('sortOrder', 0),
            'active' => $request->boolean('active', true),
        ]);
        AuditLogger::logAsCurrentUser($isNew ? 'create' : 'update', 'achievement', $def->title);

        return $this->ok([
            'id' => $def->id,
            'title' => $def->title,
            'subtitle' => $def->subtitle,
            'triggerKind' => $def->trigger_kind,
            'threshold' => (int) $def->threshold,
            'sortOrder' => (int) $def->sort_order,
            'active' => (bool) $def->active,
        ], $isNew ? 201 : 200);
    }

    public function destroy(string $id): JsonResponse
    {
        $def = AchievementDefinition::find($id);
        if ($def) {
            AuditLogger::logAsCurrentUser('delete', 'achievement', $def->title);
            $def->delete();
        }

        return $this->ok(['deleted' => true]);
    }
}
