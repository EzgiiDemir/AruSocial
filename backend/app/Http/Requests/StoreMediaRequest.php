<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\FailsWithApiValidation;
use Illuminate\Foundation\Http\FormRequest;

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

    public function rules(): array
    {
        $config = config('services.media_uploads');
        $images = implode(',', $config['image_mimes']);
        $videos = implode(',', $config['video_mimes']);

        // Laravel sizes are in kilobytes.
        $maxKb = (int) ceil(max($config['max_image_bytes'], $config['max_video_bytes']) / 1024);

        return [
            'file' => [
                'required',
                'file',
                'mimetypes:'.$images.','.$videos,
                'max:'.$maxKb,
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Bir dosya seçmelisin.',
            'file.mimetypes' => 'Bu dosya türü desteklenmiyor. '
                .'Fotoğraf için JPG, PNG, WEBP veya HEIC; video için MP4, MOV veya WEBM yükleyebilirsin.',
            'file.max' => 'Dosya çok büyük. Fotoğraflar en fazla 12 MB, videolar en fazla 100 MB olabilir.',
        ];
    }
}
