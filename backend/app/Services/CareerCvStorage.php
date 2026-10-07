<?php

namespace App\Services;

use App\Models\CareerProfile;
use App\Models\User;
use App\Support\UploadMagic;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CareerCvStorage
{
    public const DISK = 'local';

    public const MAX_BYTES = 8 * 1024 * 1024;

    public const MIMES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    public const EXTENSIONS = ['pdf', 'doc', 'docx'];

    public static function store(User $user, UploadedFile $file): array
    {
        if (! UploadMagic::isDocument($file)) {
            throw ValidationException::withMessages([
                'file' => 'File contents do not match a PDF or Word document.',
            ]);
        }

        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'pdf');
        $name = 'cv-'.$user->id.'-'.Str::uuid().'.'.$ext;
        $path = $file->storeAs('cvs/'.$user->id, $name, self::DISK);

        return [
            'path' => $path,
            'original' => $file->getClientOriginalName(),
            'mime' => $file->getMimeType() ?: 'application/pdf',
            'size' => $file->getSize(),
        ];
    }

    public static function delete(?string $path): void
    {
        if ($path) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    public static function download(CareerProfile $profile): StreamedResponse
    {
        return Storage::disk(self::DISK)->download(
            $profile->cv_path,
            $profile->cv_original_name ?: 'cv.pdf',
            ['Content-Type' => $profile->cv_mime ?: 'application/pdf'],
        );
    }

    public static function downloadPath(string $path, ?string $downloadName, ?string $mime): StreamedResponse
    {
        return Storage::disk(self::DISK)->download(
            $path,
            $downloadName ?: 'cv.pdf',
            ['Content-Type' => $mime ?: 'application/pdf'],
        );
    }

    public static function exists(?string $path): bool
    {
        return $path !== null && $path !== '' && Storage::disk(self::DISK)->exists($path);
    }
}
