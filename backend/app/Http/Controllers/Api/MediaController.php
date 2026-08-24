<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMediaRequest;
use App\Models\MediaItem;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

// Real multipart upload to real local disk storage
// (storage/app/public/media, served via `php artisan storage:link`) — not
// base64-in-the-database like the on-device MediaLibraryStore. This is
// what docs/EKSIKLER.md §12 asked for concretely: a real upload API +
// real storage, with real file-type/size checks. Cloud storage/CDN would
// be a `Storage::disk()` swap, not a rewrite, once that's needed at scale.
class MediaController extends Controller
{
    use ApiResponds;

    private const MAX_BYTES = 8 * 1024 * 1024; // 8MB
    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    private function toJson(MediaItem $m): array
    {
        return [
            'id' => $m->id,
            'url' => $m->url(),
            'fileName' => $m->file_name,
            'uploadedAt' => $m->uploaded_at?->toIso8601String(),
            'uploadedBy' => $m->uploaded_by,
            'usedIn' => $m->used_in ?? [],
        ];
    }

    public function index(): JsonResponse
    {
        return $this->ok(MediaItem::orderByDesc('uploaded_at')->get()->map(fn ($m) => $this->toJson($m)));
    }

    public function store(StoreMediaRequest $request): JsonResponse
    {
        $file = $request->file('file');
        if ($file->getSize() > self::MAX_BYTES) {
            return $this->fail(400, 'FILE_TOO_LARGE', 'File exceeds the 8MB limit.');
        }
        if (! in_array($file->getMimeType(), self::ALLOWED_MIME, true)) {
            return $this->fail(400, 'UNSUPPORTED_FILE_TYPE', 'Only JPEG/PNG/WEBP/GIF images are accepted.');
        }

        $id = 'media-'.\Illuminate\Support\Str::uuid();
        $path = $file->store('media', MediaItem::disk());
        $item = MediaItem::create([
            'id' => $id,
            'file_path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'uploaded_at' => now(),
            'uploaded_by' => $this->currentUser()->name,
            'used_in' => [],
        ]);
        AuditLogger::logAsCurrentUser('create', 'media', $item->file_name);

        return $this->ok($this->toJson($item), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $item = MediaItem::find($id);
        if (! $item) return $this->fail(404, 'MEDIA_NOT_FOUND', 'Media item not found.');

        if ($request->has('fileName')) $item->file_name = $request->input('fileName');
        if ($request->has('usedIn')) $item->used_in = $request->input('usedIn');
        $item->save();
        if ($request->has('fileName')) {
            AuditLogger::logAsCurrentUser('update', 'media', $item->file_name);
        }

        return $this->ok($this->toJson($item));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $item = MediaItem::find($id);
        if ($item) {
            Storage::disk(MediaItem::disk())->delete($item->file_path);
            AuditLogger::logAsCurrentUser('delete', 'media', $item->file_name);
            $item->delete();
        }

        return $this->ok(['deleted' => true]);
    }
}
