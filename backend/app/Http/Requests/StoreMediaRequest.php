<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use App\Support\ImageDimensions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

/**
 * The first gate an upload passes: is this even a file we accept?
 *
 * Everything here is cheap and decided before the bytes reach storage or
 * the moderation model. The rules were `required|file` — which accepted a
 * 2GB archive renamed to .jpg — so the shape of an upload was only ever
 * discovered downstream, if at all.
 *
 * The MIME check is an allowlist, and Laravel's `mimetypes` rule reads the
 * file's actual content rather than trusting the client's Content-Type or
 * the extension, so renaming a file does not get it through.
 */
class StoreMediaRequest extends FormRequest
{
    use FailsWithApiValidation;

    protected string $validationMessage = 'Yüklenen dosya kabul edilmedi.';

    /** Video containers, kept here only to be refused by name. */
    private const VIDEO_MIME = ['video/mp4', 'video/quicktime', 'video/webm'];

    /**
     * Answer a video upload before validation, not after.
     *
     * Video was removed from the product on 14 September 2026. Once its
     * MIME types left the allowlist the `mimetypes` rule refused clips on
     * its own — correctly, but with the generic VALIDATION code and a
     * message listing the image formats, which tells the user of an older
     * build nothing about what happened to the feature.
     *
     * This runs before the rules, so the specific answer wins. It reads
     * the sniffed MIME type rather than the extension or the client's
     * Content-Type, so renaming an .mp4 does not route around it.
     */
    protected function prepareForValidation(): void
    {
        $file = $this->file('file');

        if ($file === null || is_array($file) || ! $file->isValid()) {
            return;
        }

        if (! in_array($file->getMimeType(), self::VIDEO_MIME, true)) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'data' => null,
            'meta' => ['request_id' => 'req-'.Str::uuid()],
            'error' => [
                'code' => 'VIDEO_NOT_SUPPORTED',
                'message' => 'Video paylaşımı artık desteklenmiyor. Lütfen '
                    .'uygulamayı güncelleyin ve fotoğraf paylaşın.',
            ],
        ], 415));
    }

    public function rules(): array
    {
        $config = config('services.media_uploads');

        // Images only. Video was removed from the product on 14 September
        // 2026 — see MediaController for the explicit rejection an older
        // client gets, which is a clearer answer than this allowlist's
        // generic "unsupported type".
        $images = implode(',', $config['image_mimes']);

        // Laravel sizes are in kilobytes.
        $maxKb = (int) ceil($config['max_image_bytes'] / 1024);

        return [
            'file' => [
                'required',
                'file',
                'mimetypes:'.$images,
                'max:'.$maxKb,
            ],
        ];
    }

    /**
     * The pixel-count check, which the byte-size rule above cannot make.
     *
     * A decompression bomb is a *small* file: a single-colour PNG at
     * 50,000 x 50,000 is a few kilobytes on disk and about ten gigabytes
     * once decoded. `max:` sees a small file and waves it through, and the
     * first thing to actually decode it is what falls over.
     *
     * Runs after the rules so it only ever sees something already
     * established to be an image of an accepted type.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $file = $this->file('file');

            if ($file === null || is_array($file) || ! $file->isValid()) {
                return;
            }
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $dimensions = ImageDimensions::read($file);

            // Unmeasurable is not this check's business. "Claims to be a
            // JPEG but is not one" is already answered by MediaController's
            // magic-byte check with INVALID_FILE_CONTENTS, which is a more
            // specific answer than a validation error and is what clients
            // are already written against. Adding a second, vaguer refusal
            // here would only make a spoofed upload harder to explain.
            if ($dimensions === null) {
                return;
            }

            if ($reason = $dimensions->rejectionReason()) {
                $validator->errors()->add('file', $reason);
            }
        });
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Bir dosya seçmelisin.',
            'file.mimetypes' => 'Bu dosya türü desteklenmiyor. '
                .'JPG, PNG, WEBP veya HEIC yükleyebilirsin.',
            'file.max' => 'Dosya çok büyük. Fotoğraflar en fazla 12 MB olabilir.',
        ];
    }
}
