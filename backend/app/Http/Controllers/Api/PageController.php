<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpsertPageRequest;
use App\Models\AdminPage;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PageController extends Controller
{
    use ApiResponds;

    private function toJson(AdminPage $p): array
    {
        return [
            'id' => $p->id,
            'title' => $p->title,
            'slug' => $p->slug,
            'blocks' => $p->blocks ?? [],
            'status' => $p->status,
            'updatedAt' => $p->updated_at?->toIso8601String(),
            'updatedBy' => $p->updated_by,
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $query = AdminPage::query();
        if ($request->query('publishedOnly') === 'true') {
            $query->where('status', 'published');
        }

        return $this->ok($query->get()->map(fn ($p) => $this->toJson($p)));
    }

    public function show(string $slug): JsonResponse
    {
        $page = AdminPage::where('slug', $slug)->first();
        if (! $page) return $this->fail(404, 'PAGE_NOT_FOUND', 'Page not found.');

        return $this->ok($this->toJson($page));
    }

    public function upsert(UpsertPageRequest $request): JsonResponse
    {
        $id = $request->input('id');
        $title = $request->input('title');
        $slug = $request->input('slug');

        $isNew = ! AdminPage::where('id', $id)->exists();
        $page = AdminPage::updateOrCreate(['id' => $id], [
            'title' => $title,
            'slug' => $slug,
            'blocks' => $request->input('blocks', []),
            'status' => $request->input('status', 'draft'),
            'updated_at' => now(),
            'updated_by' => $request->input('actorName', 'admin'),
        ]);
        AuditLogger::logAsCurrentUser($isNew ? 'create' : 'update', 'page', $title);

        return $this->ok($this->toJson($page));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $page = AdminPage::find($id);
        if ($page) {
            AuditLogger::logAsCurrentUser('delete', 'page', $page->title);
            $page->delete();
        }

        return $this->ok(['deleted' => true]);
    }
}
