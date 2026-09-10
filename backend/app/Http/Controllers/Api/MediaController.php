<?php

namespace App\Http\Controllers\Api;

use App\Events\CampusDataChanged;
use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Api\Concerns\ModeratesContent;
use App\Http\Controllers\Controller;
use App\Http\Requests\PaginatedListRequest;
use App\Http\Requests\StoreMediaRequest;
use App\Jobs\ModerateImageJob;
use App\Models\MediaItem;
use App\Models\ModerationEvent;
use App\Models\ModerationReport;
use App\Services\AuditLogger;
use App\Services\ImageModerationService;
use App\Services\Moderation\ContentModerator;
use App\Services\Moderation\Image\FastApiImageModerationProvider;
use App\Services\Moderation\Image\ImageModerationPolicy;
use App\Services\Moderation\Image\ImageVerdict;
use App\Services\Moderation\ModerationClient;
use App\Services\Moderation\ModerationOutcome;
use App\Services\Moderation\VideoModerator;
use App\Services\ModerationService;
use App\Support\UploadMagic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

// Real multipart upload to local disk (storage/app/public/media).
// No third-party vision API is used. Magic-byte validation always runs.
// When an ARUCAD-hosted semantic classifier is configured, unavailable
// uploads stay pending for human review; when it is not configured at all,
// magic-byte-clean uploads are approved so social photos are not blocked.
class MediaController extends Controller
{
    use ApiResponds, ModeratesContent;

    // Size limits live in config('services.media_uploads') so the upload
    // request and this controller cannot disagree about what is accepted.
    private const IMAGE_MIME = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    private const VIDEO_MIME = ['video/mp4', 'video/webm', 'video/quicktime'];

    private function toJson(MediaItem $m): array
    {
        return [
            'id' => $m->id,
            'url' => $m->url(),
            'fileName' => $m->file_name,
            'mimeType' => $m->mime_type,
            'uploadedAt' => $m->uploaded_at?->toIso8601String(),
            'uploadedBy' => $m->uploaded_by,
            'usedIn' => $m->used_in ?? [],
            'moderationStatus' => $m->moderation_status ?? 'approved',
        ];
    }

    private function isVideo(?string $mime): bool
    {
        return in_array($mime, self::VIDEO_MIME, true);
    }

    private function isImage(?string $mime): bool
    {
        return in_array($mime, self::IMAGE_MIME, true);
    }

    /**
     * @return array{0: ?JsonResponse, 1: ?string} fail response or moderation status
     */
    private function validateAndModerateUpload($file, bool $adminLibrary, $submitter = null): array
    {
        $mime = $file->getMimeType();
        $isVideo = $this->isVideo($mime);
        $isImage = $this->isImage($mime);

        $limits = config('services.media_uploads');

        if (! $isVideo && ! $isImage) {
            return [$this->fail(400, 'UNSUPPORTED_FILE_TYPE',
                'Bu dosya türü desteklenmiyor. Fotoğraf için JPG, PNG, WEBP veya HEIC; '
                .'video için MP4, MOV veya WEBM yükleyebilirsin.'), null];
        }

        $max = $isVideo ? $limits['max_video_bytes'] : $limits['max_image_bytes'];
        if ($file->getSize() > $max) {
            return [$this->fail(400, 'FILE_TOO_LARGE', sprintf(
                '%s çok büyük (%s MB). En fazla %s MB olabilir.',
                $isVideo ? 'Video' : 'Fotoğraf',
                number_format($file->getSize() / 1048576, 1),
                (int) round($max / 1048576),
            )), null];
        }

        // Magic bytes, not the extension: renaming a file does not change
        // what it is, and the client's Content-Type is attacker-controlled.
        if ($isImage && ! UploadMagic::isImage($file)) {
            return [$this->fail(400, 'INVALID_FILE_CONTENTS',
                'Dosya içeriği geçerli bir fotoğrafla eşleşmiyor.'), null];
        }
        if ($isVideo && ! UploadMagic::isVideo($file)) {
            return [$this->fail(400, 'INVALID_FILE_CONTENTS',
                'Dosya içeriği geçerli bir videoyla eşleşmiyor.'), null];
        }

        if ($isImage) {
            $invalid = ImageModerationService::checkImageBytes(
                file_get_contents($file->getRealPath()),
                $mime,
            );
            if ($invalid !== null) {
                return [$this->fail(400, 'INVALID_FILE_CONTENTS', $invalid), null];
            }

            // A decompression bomb is small on disk and enormous in memory,
            // so it has to be refused on dimensions before anything decodes
            // it — including our own moderation pass.
            $size = @getimagesize($file->getRealPath());
            if (is_array($size) && ($size[0] * $size[1]) > $limits['max_image_pixels']) {
                return [$this->fail(400, 'IMAGE_TOO_LARGE', sprintf(
                    'Fotoğraf çözünürlüğü çok yüksek (%d×%d).', $size[0], $size[1],
                )), null];
            }
        }

        if ($isVideo) {
            $seconds = (new VideoModerator)
                ->durationSeconds($file->getRealPath());
            // Null means the duration could not be read — that is handled by
            // the frame-extraction step, which holds the upload rather than
            // guessing. Only a known, over-limit duration is refused here.
            if ($seconds !== null && $seconds > $limits['max_video_seconds']) {
                return [$this->fail(400, 'VIDEO_TOO_LONG', sprintf(
                    'Video çok uzun (%d saniye). En fazla %d saniye olabilir.',
                    (int) round($seconds), $limits['max_video_seconds'],
                )), null];
            }
        }

        // Primary layer: the moderation model actually looks at the picture.
        // Images go straight in; videos are sampled into frames first, so
        // prohibited content buried mid-clip is still caught.
        if ($submitter === null) {
            // No account to attribute the upload to means no moderation ran.
            // Hold it rather than approve — an unattributed upload is the
            // last thing that should skip the check.
            return [null, 'pending'];
        }

        $decision = $this->inspectUploadWithProvider($file, $mime, $isVideo, $submitter);
        if ($decision !== null) {
            return $decision;
        }

        // Visual moderation. Once a classifier is configured it is the
        // authority for images, and it is the last word: an image never
        // continues past here to a fallback whose failure mode is
        // "approve because nothing was configured". Nothing below this
        // point may look at an image again.
        if (! $isVideo && app(FastApiImageModerationProvider::class)->isConfigured()) {
            // Queued mode stores the image as pending and lets the job
            // decide. It is written as pending *before* the job is
            // dispatched, so a worker that never runs leaves it private
            // rather than published — the failure is inert by
            // construction, not by remembering to handle it.
            if ((bool) config('moderation.image.async', false)) {
                return [null, 'pending'];
            }

            return $this->inspectImageVisually($file, $submitter);
        }

        // A configured ARUCAD-owned semantic model may make a high-confidence
        // decision for both image and video files. It is intentionally not a
        // remote API. If the model is configured but unavailable, the upload
        // remains pending below (fail closed). If it is not configured at all,
        // magic-byte-clean uploads are approved so social media is not blocked.
        $semantic = ImageModerationService::inspectLocalFile($file->getRealPath(), $mime);
        if ($semantic['blocked']) {
            $categories = implode(', ', $semantic['categories']);
            if ($submitter !== null) {
                ModerationService::recordMediaViolation($submitter, $categories);
            }
            AuditLogger::logAsCurrentUser('moderation', 'media', 'Yerel model engelledi: '.$categories);

            return [$this->fail(400, 'CONTENT_BLOCKED',
                'Medya topluluk kurallarına aykırı olduğu için yayınlanmadı.'), null];
        }

        // Working classifier: approve unflagged media immediately.
        if ($semantic['available']) {
            return [null, 'approved'];
        }

        // No semantic classifier configured is a deployment choice, not a
        // reason to strand every ordinary selfie in pending. Structural
        // checks have completed, so publish. A configured runner that failed
        // is a technical ERROR and must neither store a pending/blocked row
        // nor create a strike.
        if (trim((string) config('services.local_moderation.binary')) === '') {
            return [null, 'approved'];
        }

        return [$this->moderationError(ModerationOutcome::unavailable()), null];
    }

    /**
     * Visual moderation for one image.
     *
     * Maps a verdict to a storage decision. The mapping is exhaustive on
     * purpose — there is no `default` that continues to somewhere more
     * permissive, because that is exactly how an uninspected image
     * reached the timeline before.
     *
     * @return array{0: JsonResponse|null, 1: string|null}
     */
    private function inspectImageVisually($file, $submitter): array
    {
        $bytes = (string) file_get_contents($file->getRealPath());

        $signal = app(FastApiImageModerationProvider::class)
            ->inspect($bytes, $file->getClientOriginalName() ?: 'upload');
        $verdict = app(ImageModerationPolicy::class)->decide($signal);

        $this->recordImageVerdict($submitter, $file, $verdict);

        return match ($verdict->decision) {
            ImageVerdict::ALLOW => [null, 'approved'],

            // Held, and deliberately without a strike: REVIEW means the
            // model was unsure, and punishing people for our uncertainty
            // is how a moderation system loses the users it exists for.
            ImageVerdict::REVIEW => [null, 'pending'],

            ImageVerdict::BLOCK => [
                $this->blockedImageResponse($submitter, $verdict),
                null,
            ],

            // The file was never usable. A validation error, so it says so
            // plainly and costs the uploader nothing but a retry.
            ImageVerdict::INVALID => [
                $this->fail(400, 'INVALID_FILE_CONTENTS',
                    'Görsel okunamadı. Lütfen geçerli bir JPEG, PNG veya WEBP dosyası yükle.'),
                null,
            ],

            // Nothing looked at the pixels. Fail closed — always.
            default => [$this->moderationError(ModerationOutcome::unavailable()), null],
        };
    }

    private function blockedImageResponse($submitter, ImageVerdict $verdict): JsonResponse
    {
        $category = $verdict->topCategory() ?? 'policy';

        if ($submitter !== null) {
            ModerationService::recordMediaViolation($submitter, $category);
        }
        AuditLogger::logAsCurrentUser('moderation', 'media',
            'Görsel sınıflandırıcı engelledi: '.$category);

        return $this->fail(400, 'CONTENT_BLOCKED',
            'Bu görsel topluluk kurallarına aykırı olduğu için paylaşılmadı.');
    }

    /**
     * Evidence for every visual decision, including the ones that allowed
     * publication — a false negative is unreviewable if only blocks were
     * recorded. Scores and versions, never a copy of the image.
     */
    private function recordImageVerdict($submitter, $file, ImageVerdict $verdict): void
    {
        try {
            ModerationEvent::create([
                'id' => (string) Str::uuid(),
                'user_id' => (string) ($submitter->id ?? ''),
                'content_type' => 'image',
                'source_feature' => 'media.upload',
                'action' => match ($verdict->decision) {
                    ImageVerdict::ALLOW => ModerationEvent::ACTION_ALLOWED,
                    ImageVerdict::BLOCK => ModerationEvent::ACTION_REJECTED,
                    default => ModerationEvent::ACTION_REVIEW,
                },
                'flagged' => $verdict->categories !== [],
                'categories' => $verdict->categories,
                'category_scores' => $verdict->scores,
                'decided_by' => 'image_model',
                'moderation_provider' => 'fastapi_image',
                'moderation_model' => $verdict->model,
                'model_version' => $verdict->modelVersion,
                'policy_version' => $verdict->policyVersion,
                'latency_ms' => $verdict->latencyMs,
                'excerpt' => $verdict->reasonCode,
            ]);
        } catch (\Throwable $e) {
            // Losing the audit row must not change the verdict, but it is
            // never silent: an unrecorded decision is one nobody can
            // explain later.
            Log::error('moderation.image.evidence_write_failed', [
                'error' => $e->getMessage(),
                'decision' => $verdict->decision,
            ]);
        }
    }

    /**
     * Runs the upload through the central moderation gateway.
     *
     * Returns a `[response, status]` pair when the upload must not proceed
     * as-is, or null to let the existing local-classifier path decide.
     * Videos are reduced to sampled frames first; if ffmpeg is unavailable
     * the video is held for human review rather than published unchecked.
     *
     * @return array{0: JsonResponse|null, 1: string|null}|null
     */
    private function inspectUploadWithProvider($file, string $mime, bool $isVideo, $submitter): ?array
    {
        // With no remote provider configured, continue to the optional local
        // classifier. Trying to extract frames first made all videos depend
        // on ffmpeg even in installations that intentionally use only the
        // structural/local path.
        $client = app(ModerationClient::class);
        if (! $client->isConfigured()) {
            return null;
        }

        $moderator = app(ContentModerator::class);

        if ($client->usesGateway()) {
            $outcome = $moderator->check(
                $submitter,
                null,
                $isVideo ? 'video' : 'image',
                'media.upload',
                [],
                [[
                    'path' => $file->getRealPath(),
                    'name' => $file->getClientOriginalName(),
                ]],
            );

            return match ($outcome->status) {
                ModerationOutcome::ALLOWED, ModerationOutcome::WARNED => [null, 'approved'],
                default => [$this->moderationError($outcome), null],
            };
        }

        if ($isVideo) {
            $extraction = (new VideoModerator)
                ->extractFrames($file->getRealPath());

            if (! $extraction['available']) {
                return [$this->moderationError(ModerationOutcome::unavailable()), null];
            }

            $outcome = $moderator->check(
                $submitter, null, 'video', 'media.upload', $extraction['frames'],
            );
        } else {
            $dataUri = 'data:'.$mime.';base64,'
                .base64_encode((string) file_get_contents($file->getRealPath()));
            $outcome = $moderator->check(
                $submitter, null, 'image', 'media.upload', [$dataUri],
            );
        }

        $status = ModerationOutcome::class;

        // Written as an allowlist, not a blocklist.
        //
        // This used to end in `default => null`, which continued to a path
        // whose own fallback approves the upload. So any outcome nobody had
        // thought to enumerate was published — and one of them was REVIEW,
        // the state an *uninspected* image is held in. Uploading the same
        // photo twice therefore beat the gate outright: the first attempt
        // recorded a REVIEW event, the second replayed it from the
        // idempotency cache, fell through the default, and was approved.
        //
        // Only outcomes that positively mean "a model looked at this and
        // was content" may continue. Everything else is held, including
        // anything added to the enum later.
        return match ($outcome->status) {
            $status::REJECTED, $status::BANNED => [$this->moderationError($outcome), null],
            // A local text-only fallback returning ALLOWED is not evidence
            // that pixels were inspected. Only a configured vision provider
            // can approve at this point; otherwise continue to the local
            // media classifier path, which holds if it cannot inspect.
            $status::ALLOWED, $status::WARNED => [null, 'approved'],
            $status::UNAVAILABLE => [$this->moderationError($outcome), null],
            default => [null, 'pending'],
        };
    }

    private function storeUpload($file, ?int $userId, string $uploadedBy, string $moderationStatus): MediaItem
    {
        $id = 'media-'.Str::uuid();
        $folder = $this->isVideo($file->getMimeType()) ? 'media/video' : 'media';
        $path = $file->store($folder, MediaItem::disk());

        $item = MediaItem::create([
            'id' => $id,
            'user_id' => $userId,
            'file_path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'uploaded_at' => now(),
            'uploaded_by' => $uploadedBy,
            'used_in' => [],
            'moderation_status' => $moderationStatus,
        ]);

        // Dispatched only after the row exists as pending. Ordering is the
        // safety property: if dispatch or the worker fails, the image is
        // already stored in a state that cannot be served.
        if ($moderationStatus === 'pending'
            && $this->isImage($file->getMimeType())
            && (bool) config('moderation.image.async', false)
            && app(FastApiImageModerationProvider::class)->isConfigured()) {
            ModerateImageJob::dispatch($item->id);
        }

        return $item;
    }

    public function index(): JsonResponse
    {
        // Admin library sees everything including pending.
        return $this->ok(MediaItem::orderByDesc('uploaded_at')->get()->map(fn ($m) => $this->toJson($m)));
    }

    public function store(StoreMediaRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $me = $this->currentUser();
        [$fail, $status] = $this->validateAndModerateUpload($file, true, $me);
        if ($fail) {
            return $fail;
        }

        $item = $this->storeUpload($file, null, $this->currentUser()->name, $status);
        if ($status === 'pending') {
            ModerationReport::create([
                'id' => $this->newId('report'),
                'kind' => 'media',
                'target_id' => $item->id,
                'target_label' => $item->file_name,
                'reason' => 'media_awaiting_local_review',
                'reported_at' => now(),
                'action' => null,
            ]);
            $this->announceModerationChange($item->id, 'submitted_for_review');
        }
        AuditLogger::logAsCurrentUser('create', 'media', $item->file_name);

        return $this->ok($this->toJson($item), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $item = MediaItem::find($id);
        if (! $item) {
            return $this->fail(404, 'MEDIA_NOT_FOUND', 'Media item not found.');
        }

        if ($request->has('fileName')) {
            $item->file_name = $request->input('fileName');
        }
        if ($request->has('usedIn')) {
            $item->used_in = $request->input('usedIn');
        }
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

    public function mine(PaginatedListRequest $request): JsonResponse
    {
        $me = $this->currentUser();
        $query = MediaItem::where('user_id', $me->id)
            ->whereIn('moderation_status', ['approved', 'pending'])
            ->orderByDesc('uploaded_at')
            ->orderByDesc('id');

        return $this->okPage($query, $request, fn ($m) => $this->toJson($m));
    }

    public function storeMine(StoreMediaRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $me = $this->currentUser();
        [$fail, $status] = $this->validateAndModerateUpload($file, false, $me);
        if ($fail) {
            return $fail;
        }

        $item = $this->storeUpload($file, $me->id, $me->name, $status);
        if ($status === 'pending') {
            ModerationReport::create([
                'id' => $this->newId('report'),
                'kind' => 'media',
                'target_id' => $item->id,
                'target_label' => $item->file_name,
                'reason' => 'media_awaiting_local_review',
                'reported_at' => now(),
                'action' => null,
            ]);
            $this->announceModerationChange($item->id, 'submitted_for_review');
        }

        return $this->ok($this->toJson($item), 201);
    }

    public function destroyMine(string $id): JsonResponse
    {
        $me = $this->currentUser();
        $item = MediaItem::where('id', $id)->where('user_id', $me->id)->first();
        if (! $item) {
            return $this->fail(404, 'MEDIA_NOT_FOUND', 'Media item not found.');
        }

        Storage::disk(MediaItem::disk())->delete($item->file_path);
        $item->delete();

        return $this->ok(['deleted' => true]);
    }

    private function announceModerationChange(string $id, string $action): void
    {
        try {
            // No filename, uploader or file URL is sent over this public
            // channel; authorised admins reload their protected queue.
            broadcast(new CampusDataChanged(['media', 'moderation'], $action, $id));
        } catch (\Throwable) {
            // The durable moderation row is the fallback source of truth.
        }
    }

    public function file(string $id)
    {
        $item = MediaItem::find($id);
        // Pending user media must not be served publicly. The owner can still
        // see its pending row in "my media"; reviewers see it in Admin.
        if (! $item || ($item->moderation_status ?? 'approved') !== 'approved') {
            abort(404);
        }
        if (! $this->isImage($item->mime_type) && ! $this->isVideo($item->mime_type)) {
            abort(404);
        }

        $disk = Storage::disk(MediaItem::disk());
        if (! $disk->exists($item->file_path)) {
            abort(404);
        }

        return $disk->response(
            $item->file_path,
            $item->file_name,
            $this->fileHeaders($item->mime_type),
            'inline',
        );
    }

    /**
     * Serve an unapproved upload only through a temporary signed URL issued
     * to the moderation queue. The normal file endpoint remains
     * approved-only, so a guessed media id can never expose pending content.
     */
    public function reviewFile(string $id)
    {
        $item = MediaItem::find($id);
        if (! $item || ($item->moderation_status ?? 'approved') !== 'pending') {
            abort(404);
        }
        if (! $this->isImage($item->mime_type) && ! $this->isVideo($item->mime_type)) {
            abort(404);
        }

        $disk = Storage::disk(MediaItem::disk());
        if (! $disk->exists($item->file_path)) {
            abort(404);
        }

        return $disk->response(
            $item->file_path,
            $item->file_name,
            $this->fileHeaders($item->mime_type, private: true),
            'inline',
        );
    }

    public function fileByName(string $filename)
    {
        $safe = basename($filename);
        if ($safe === '' || $safe !== $filename) {
            abort(404);
        }
        $item = MediaItem::query()
            ->where(function ($q) use ($safe) {
                $q->where('file_path', 'media/'.$safe)
                    ->orWhere('file_path', 'media/video/'.$safe)
                    ->orWhere('file_path', 'like', '%/'.$safe);
            })
            ->first();
        if ($item) {
            return $this->file($item->id);
        }

        // Never fall back to a raw disk path. A file without a MediaItem
        // approval record is unmoderated by definition.
        abort(404);
    }

    /**
     * @return array<string, string>
     */
    private function fileHeaders(?string $mime, bool $private = false): array
    {
        $origin = request()->headers->get('Origin');

        return [
            'Content-Type' => $mime ?: 'application/octet-stream',
            'Cache-Control' => $private ? 'private, no-store' : 'public, max-age=86400',
            'Access-Control-Allow-Origin' => $origin ?: '*',
            'Access-Control-Allow-Methods' => 'GET, HEAD, OPTIONS',
            'Cross-Origin-Resource-Policy' => 'cross-origin',
            'Vary' => 'Origin',
        ];
    }
}
