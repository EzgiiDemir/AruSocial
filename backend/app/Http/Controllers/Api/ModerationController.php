<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\CheckModerationImageRequest;
use App\Services\ImageModerationService;
use Illuminate\Http\JsonResponse;

class ModerationController extends Controller
{
    use ApiResponds;

    private const MAX_BYTES = 8 * 1024 * 1024; // 8MB — matches MediaController's limit.

    // Local structural validation only. Semantic moderation never leaves
    // ARUCAD infrastructure: the actual upload is held in the local admin
    // queue until a moderator approves it.
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
        $invalid = ImageModerationService::checkImageBytes($bytes, $mimeType);
        if ($invalid === null) {
            // The base64 preflight has no durable file path for the local
            // model runner. The real multipart upload is inspected again by
            // MediaController, then always remains non-public until reviewed.
            return $this->ok([
                'allowed' => true,
                'reviewRequired' => true,
                'semanticModel' => 'checked_on_upload',
            ]);
        }
        return $this->fail(400, 'INVALID_FILE_CONTENTS',
            "Fotoğraf dosyası geçersiz ($invalid). Lütfen başka bir dosya seç.");
    }
}
