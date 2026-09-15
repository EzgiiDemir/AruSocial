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

    // Fast structural preflight only. The durable multipart upload runs the
    // configured semantic path in MediaController.
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
            // This response is not a moderation verdict. The multipart
            // upload is inspected again and applies ALLOW/BLOCK/ERROR.
            return $this->ok([
                'allowed' => true,
                'reviewRequired' => false,
                'semanticModel' => 'checked_on_upload',
            ]);
        }
        return $this->fail(400, 'INVALID_FILE_CONTENTS',
            "Fotoğraf dosyası geçersiz ($invalid). Lütfen başka bir dosya seç.");
    }
}
