<?php

namespace Tests\Unit;

use App\Support\ImageDimensions;
use PHPUnit\Framework\TestCase;

/**
 * Measuring an upload before anything tries to decode it.
 *
 * The case that matters is the decompression bomb: a file that is small
 * on disk and enormous once decoded. Every size check the app had looked
 * at bytes, and bytes are exactly what a bomb is cheap on — so the only
 * thing that can catch it is the header, read without decoding.
 */
class ImageDimensionsTest extends TestCase
{
    /** A real 1x1 PNG, so there is something genuine to measure. */
    private function pngFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'dim').'.png';
        file_put_contents($path, (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQ'
            .'DwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true,
        ));

        return $path;
    }

    public function test_a_real_image_is_measured_from_its_header(): void
    {
        $path = $this->pngFile();

        try {
            $dimensions = ImageDimensions::fromPath($path);

            $this->assertNotNull($dimensions);
            $this->assertSame(1, $dimensions->width);
            $this->assertSame(1, $dimensions->height);
            $this->assertSame('image/png', $dimensions->mime);
        } finally {
            @unlink($path);
        }
    }

    public function test_a_file_that_is_not_an_image_cannot_be_measured(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'dim');
        file_put_contents($path, str_repeat('A', 512));

        try {
            $this->assertNull(ImageDimensions::fromPath($path));
        } finally {
            @unlink($path);
        }
    }

    public function test_a_missing_or_empty_path_is_null_rather_than_an_error(): void
    {
        $this->assertNull(ImageDimensions::fromPath(''));
        $this->assertNull(ImageDimensions::fromPath('/no/such/file.jpg'));
    }

    public function test_an_ordinary_photo_is_accepted(): void
    {
        // A 48-megapixel phone photo, which is a real thing people own.
        $dimensions = new ImageDimensions(8000, 6000, 'image/jpeg');

        $this->assertNull($dimensions->rejectionReason());
        $this->assertSame(48_000_000, $dimensions->pixels());
    }

    public function test_a_decompression_bomb_is_refused_on_pixel_count(): void
    {
        // Roughly 2.5 billion pixels: a few kilobytes on disk as a flat
        // PNG, about ten gigabytes decoded.
        $dimensions = new ImageDimensions(50_000, 50_000, 'image/png');

        $this->assertNotNull($dimensions->rejectionReason());
    }

    /**
     * Within the pixel budget and still unusable.
     */
    public function test_an_extreme_single_side_is_refused_even_when_small(): void
    {
        $dimensions = new ImageDimensions(1, 60_000, 'image/png');

        $this->assertLessThan(ImageDimensions::MAX_PIXELS, $dimensions->pixels());
        $this->assertNotNull(
            $dimensions->rejectionReason(),
            'A 1x60000 strip breaks every layout it is drawn in.',
        );
    }

    public function test_the_limits_are_where_they_say_they_are(): void
    {
        $at = new ImageDimensions(10_000, 5_000, null);
        $this->assertSame(ImageDimensions::MAX_PIXELS, $at->pixels());
        $this->assertNull($at->rejectionReason(), 'Exactly at the cap is allowed.');

        $over = new ImageDimensions(10_000, 5_001, null);
        $this->assertNotNull($over->rejectionReason());
    }

    public function test_the_aspect_ratio_is_reported_for_the_feed(): void
    {
        $this->assertSame(0.8, (new ImageDimensions(1200, 1500, null))->aspectRatio());
    }

    public function test_a_zero_height_does_not_divide_by_zero(): void
    {
        $this->assertNull((new ImageDimensions(100, 0, null))->aspectRatio());
    }

    public function test_low_resolution_is_advisory_and_not_a_rejection(): void
    {
        $small = new ImageDimensions(320, 240, 'image/jpeg');

        $this->assertTrue($small->isLowResolution());
        $this->assertNull(
            $small->rejectionReason(),
            'A small photo is the author\'s choice, not an error.',
        );
    }

    public function test_a_normal_photo_is_not_flagged_as_low_resolution(): void
    {
        $this->assertFalse((new ImageDimensions(1200, 1600, null))->isLowResolution());
    }
}
