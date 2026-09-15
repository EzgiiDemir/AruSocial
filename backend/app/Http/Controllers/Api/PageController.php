<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Api\Concerns\ModeratesContent;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpsertPageRequest;
use App\Models\AdminPage;
use App\Services\AuditLogger;
use App\Services\GranularPermissions;
use App\Services\PageVersionRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PageController extends Controller
{
    use ApiResponds, ModeratesContent;

    private function toJson(AdminPage $p): array
    {
        $requestedLocale = strtolower((string) request()->query('lang', request()->header('Accept-Language', 'tr')));
        $locale = in_array(substr($requestedLocale, 0, 2), ['tr', 'en', 'ru'], true)
            ? substr($requestedLocale, 0, 2)
            : 'tr';
        $pageTranslation = $p->translations[$locale] ?? $p->translations['tr'] ?? [];
        $platform = request()->query('platform', 'mobile');
        $now = now();
        $blocks = collect($p->blocks ?? [])->filter(function (array $block) use ($platform, $now): bool {
            $data = $block['data'] ?? $block;
            if ($platform === 'web' && ($data['visible_web'] ?? true) === false) {
                return false;
            }
            if ($platform !== 'web' && ($data['visible_mobile'] ?? true) === false) {
                return false;
            }
            if (filled($data['starts_at'] ?? null) && $now->lt($data['starts_at'])) {
                return false;
            }
            if (filled($data['ends_at'] ?? null) && $now->gte($data['ends_at'])) {
                return false;
            }

            return true;
        })->map(function (array $block) use ($locale): array {
            $data = $block['data'] ?? $block;
            $translation = $data['translations'][$locale] ?? $data['translations']['tr'] ?? [];
            $data = array_merge($data, $translation);
            unset($data['translations']);

            if (array_key_exists('data', $block)) {
                $block['data'] = $data;

                return $block;
            }

            return $data;
        })->values()->all();

        return [
            'id' => $p->id,
            'title' => $pageTranslation['title'] ?? $p->title,
            'summary' => $pageTranslation['summary'] ?? null,
            'slug' => $p->slug,
            'locale' => $locale,
            'blocks' => $blocks,
            'status' => $p->status,
            'audiences' => $p->audiences ?? [],
            'publishAt' => $p->publish_at?->toIso8601String(),
            'expiresAt' => $p->expires_at?->toIso8601String(),
            'updatedAt' => $p->updated_at?->toIso8601String(),
            'updatedBy' => $p->updated_by,
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $query = AdminPage::query();
        $mayPreview = GranularPermissions::allows($this->currentUser(), 'pages.manage')
            || GranularPermissions::allows($this->currentUser(), 'pages.page.preview');
        if ($request->query('publishedOnly') === 'true' || ! $mayPreview) {
            $this->onlyCurrentlyPublished($query);
        }

        return $this->ok($query->get()->map(fn ($p) => $this->toJson($p)));
    }

    public function show(string $slug): JsonResponse
    {
        $query = AdminPage::where('slug', $slug);
        $mayPreview = GranularPermissions::allows($this->currentUser(), 'pages.manage')
            || GranularPermissions::allows($this->currentUser(), 'pages.page.preview');
        if (! $mayPreview) {
            $this->onlyCurrentlyPublished($query);
        }
        $page = $query->first();
        if (! $page) {
            return $this->fail(404, 'PAGE_NOT_FOUND', 'Page not found.');
        }

        return $this->ok($this->toJson($page));
    }

    private function onlyCurrentlyPublished($query): void
    {
        $query->where('status', 'published')
            ->where(fn ($q) => $q->whereNull('publish_at')->orWhere('publish_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function upsert(UpsertPageRequest $request): JsonResponse
    {
        $exists = AdminPage::where('id', $request->input('id'))->exists();
        $action = $exists ? 'update' : 'create';
        if (! GranularPermissions::allows($this->currentUser(), GranularPermissions::actionPermission($this->currentUser(), 'pages.manage', $action))) {
            return $this->fail(403, 'FORBIDDEN', 'Bu işlem için yetkin yok.');
        }
        if ($request->input('status') === 'published'
            && ! GranularPermissions::allows($this->currentUser(), GranularPermissions::actionPermission($this->currentUser(), 'pages.manage', 'publish'))) {
            return $this->fail(403, 'PUBLISH_FORBIDDEN', 'Bu içeriği yayınlama yetkin yok.');
        }

        if ($blocked = $this->moderationBlock(
            $this->currentUser(),
            $this->moderationText($request->safe()->only(['title', 'blocks'])),
            'cms_page',
            'admin.page.upsert',
        )) {
            return $blocked;
        }
        $id = $request->input('id');
        $title = $request->input('title');
        $slug = $request->input('slug');

        $isNew = ! $exists;
        $page = AdminPage::updateOrCreate(['id' => $id], [
            'title' => $title,
            'slug' => $slug,
            'translations' => $request->input('translations', []),
            'blocks' => $request->input('blocks', []),
            'status' => $request->input('status', 'draft'),
            'audiences' => $request->input('audiences', []),
            'publish_at' => $request->input('publishAt'),
            'expires_at' => $request->input('expiresAt'),
            'updated_at' => now(),
            'updated_by' => $request->input('actorName', 'admin'),
        ]);
        PageVersionRecorder::record($page);
        AuditLogger::logAsCurrentUser($isNew ? 'create' : 'update', 'page', $title);

        return $this->ok($this->toJson($page));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $permission = GranularPermissions::actionPermission($this->currentUser(), 'pages.manage', 'soft_delete');
        if (! GranularPermissions::allows($this->currentUser(), $permission)) {
            return $this->fail(403, 'FORBIDDEN', 'Bu işlem için yetkin yok.');
        }
        $page = AdminPage::find($id);
        if ($page) {
            AuditLogger::logAsCurrentUser('delete', 'page', $page->title);
            $page->delete();
        }

        return $this->ok(['deleted' => true]);
    }
}
