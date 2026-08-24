<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\CheckModerationImageRequest;
use App\Services\ImageModerationService;
use App\Services\ModerationService;
use Illuminate\Http\JsonResponse;

class ModerationController extends Controller
{
    use ApiResponds;

    private const MAX_BYTES = 8 * 1024 * 1024; // 8MB — matches MediaController's limit.

    // Real, fully server-side vision-moderation check (docs/EKSIKLER.md
    // §26): the client uploads the raw image bytes, the backend scans
    // them with ImageModerationService (using an API key that only ever
    // lives server-side — see AppSetting/AdminSettingsController) and, if
    // flagged, records a real strike toward the same 3-strike ban as
    // flagged text before rejecting the upload.
    public function checkImage(CheckModerationImageRequest $request): JsonResponse
    {
        $base64 = $request->input('imageBase64');
        $bytes = base64_decode($base64, true);
        if ($bytes === false) {
            return $this->fail(400, 'VALIDATION', 'imageBase64 is not valid base64.');
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            return $this->fail(400, 'FILE_TOO_LARGE', 'Image exceeds the 8MB limit.');
        }

        $mimeType = $request->input('mimeType', 'image/jpeg');
        $hit = ImageModerationService::checkImageBytes($bytes, $mimeType);
        if ($hit === null) {
            return $this->ok(['allowed' => true]);
        }

        $me = $this->currentUser();
        ModerationService::recordImageStrike($me, $hit);

        return $this->fail(400, 'CONTENT_BLOCKED',
            "Fotoğraf otomatik taramada uygunsuz görünüyor ($hit). Lütfen başka bir fotoğraf seç.");
    }
}
