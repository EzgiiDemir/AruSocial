<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\ContentRevision;
use App\Models\Draft;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContentRevisionController extends Controller
{
    use ApiResponds;

    private const CAP = 20;

    public function index(string $contentKey): JsonResponse
    {
        $rows = ContentRevision::where('content_key', $contentKey)
            ->orderByDesc('saved_at')
            ->limit(self::CAP)
            ->get();

        return $this->ok($rows->map(fn ($r) => [
            'id' => $r->id,
            'savedAt' => $r->saved_at?->toIso8601String(),
            'editorName' => $r->editor_name,
            'snapshot' => $r->snapshot,
        ]));
    }

    public function record(Request $request, string $contentKey): JsonResponse
    {
        $snapshot = $request->input('snapshot', []);
        if (empty($snapshot)) return $this->ok(['recorded' => false]);

        ContentRevision::create([
            'id' => 'rev-'.\Illuminate\Support\Str::uuid(),
            'content_key' => $contentKey,
            'editor_name' => $request->input('editorName', 'admin'),
            'snapshot' => $snapshot,
            'saved_at' => now(),
        ]);

        // Trim to the last CAP per content_key. Fetched in full then
        // sliced in PHP rather than skip()/offset() — SQLite requires an
        // explicit LIMIT alongside OFFSET (a bare ->skip() 500s here),
        // and per-content_key revision counts are always small enough
        // that this is genuinely simpler than working around that.
        $ids = ContentRevision::where('content_key', $contentKey)
            ->orderByDesc('saved_at')->pluck('id')->slice(self::CAP);
        if ($ids->isNotEmpty()) {
            ContentRevision::whereIn('id', $ids)->delete();
        }

        return $this->ok(['recorded' => true]);
    }

    public function getDraft(string $contentKey): JsonResponse
    {
        $draft = Draft::find($contentKey);

        return $this->ok($draft ? [
            'blocks' => $draft->blocks,
            'updatedAt' => $draft->updated_at?->toIso8601String(),
        ] : null);
    }

    public function saveDraft(Request $request, string $contentKey): JsonResponse
    {
        Draft::updateOrCreate(
            ['content_key' => $contentKey],
            ['blocks' => $request->input('blocks', []), 'updated_at' => now()]
        );

        return $this->ok(['saved' => true]);
    }

    public function deleteDraft(string $contentKey): JsonResponse
    {
        Draft::where('content_key', $contentKey)->delete();

        return $this->ok(['deleted' => true]);
    }
}
