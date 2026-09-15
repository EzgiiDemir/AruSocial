<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

/**
 * How big an image claims to be, and whether that is survivable.
 *
 * Byte size is not the interesting number. A PNG of one flat colour
 * compresses to a few kilobytes at 50,000 × 50,000 pixels, sails past
 * every file-size limit, and then asks whoever decodes it for roughly ten
 * gigabytes of memory — the classic decompression bomb. The file-size
 * check that already runs cannot see this, because the file really is
 * small; only the header says otherwise.
 *
 * `getimagesize()` reads that header without decoding the pixels, so the
 * cost of asking is a few bytes rather than the allocation we are trying
 * to avoid. It is part of PHP itself and does not need GD, which matters
 * here: this server has neither GD nor Imagick installed.
 */
class ImageDimensions
{
    /**
     * The most pixels an upload may contain.
     *
     * 50 megapixels is comfortably beyond any phone or mirrorless camera
     * (a 2026 flagship is around 12–50 MP, and the picker downscales to
     * 2560px wide long before this) while being far below the size at
     * which decoding becomes a memory problem. At 4 bytes per pixel this
     * caps a decode at roughly 200 MB.
     */
    public const MAX_PIXELS = 50_000_000;

    /**
     * No single side may exceed this, even within the pixel budget.
     *
     * A 1 × 60,000 image is only 60,000 pixels but breaks layout
     * everywhere it is drawn, and is never something a person meant to
     * post.
     */
    public const MAX_SIDE = 20_000;

    public function __construct(
        public readonly int $width,
        public readonly int $height,
        public readonly ?string $mime,
    ) {}

    public function pixels(): int
    {
        return $this->width * $this->height;
    }

    public function aspectRatio(): ?float
    {
        return $this->height > 0 ? round($this->width / $this->height, 6) : null;
    }

    /**
     * Reads the header of an uploaded file.
     *
     * Null means "this is not an image we can measure" — a corrupt file, a
     * format `getimagesize()` does not know, or something that is not an
     * image at all whatever it is called.
     */
    public static function read(UploadedFile $file): ?self
    {
        return self::fromPath($file->getRealPath() ?: $file->getPathname());
    }

    public static function fromPath(string $path): ?self
    {
        if ($path === '' || ! is_file($path)) {
            return null;
        }

        // Suppressed deliberately: a malformed image is an expected input
        // here, not an exceptional one, and the warning it emits would be
        // noise in the log for something already handled by returning null.
        $info = @getimagesize($path);

        if (! is_array($info) || ! isset($info[0], $info[1])) {
            return null;
        }

        $width = (int) $info[0];
        $height = (int) $info[1];

        if ($width <= 0 || $height <= 0) {
            return null;
        }

        return new self($width, $height, $info['mime'] ?? null);
    }

    /**
     * Why this image may not be accepted, or null if it is fine.
     *
     * The message is shown to whoever uploaded it, so it says what is
     * wrong with their picture rather than naming a limit constant.
     */
    public function rejectionReason(): ?string
    {
        if ($this->width > self::MAX_SIDE || $this->height > self::MAX_SIDE) {
            return sprintf(
                'Görselin bir kenarı çok uzun (%d×%d). En fazla %d piksel olabilir.',
                $this->width, $this->height, self::MAX_SIDE,
            );
        }

        if ($this->pixels() > self::MAX_PIXELS) {
            return sprintf(
                'Görsel çok büyük (%d×%d piksel). Lütfen daha küçük bir sürümünü yükle.',
                $this->width, $this->height,
            );
        }

        return null;
    }

    /**
     * Whether the picture is small enough that it will look soft.
     *
     * Advisory only. It is the author's photo and their decision; this is
     * for telling them before it is public rather than after.
     */
    public function isLowResolution(int $threshold = 600): bool
    {
        return $this->width < $threshold || $this->height < $threshold;
    }
}
