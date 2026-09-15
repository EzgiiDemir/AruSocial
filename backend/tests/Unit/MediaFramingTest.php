<?php

namespace Tests\Unit;

use App\Support\MediaFraming;
use PHPUnit\Framework\TestCase;

/**
 * The server's opinion of what framing metadata may say.
 *
 * Framing is composed freely on a client and read back by every other
 * client to position an image. Nothing here is about rejecting attacks so
 * much as refusing to store a value that cannot be drawn: a NaN scale
 * passes every naive range check, because every comparison against NaN is
 * false, and then produces an unrenderable layout on a phone belonging to
 * someone who never wrote the post.
 */
class MediaFramingTest extends TestCase
{
    public function test_anything_that_is_not_a_map_becomes_null(): void
    {
        foreach ([null, 'nonsense', 42, true, 1.5] as $junk) {
            $this->assertNull(MediaFraming::sanitize($junk));
        }
    }

    public function test_an_empty_or_wholly_unrecognised_map_becomes_null(): void
    {
        $this->assertNull(MediaFraming::sanitize([]));
        $this->assertNull(MediaFraming::sanitize(['what' => 'ever']));
    }

    public function test_nan_and_infinity_are_dropped_rather_than_stored(): void
    {
        foreach ([NAN, INF, -INF] as $bad) {
            $result = MediaFraming::sanitize([
                'scale' => $bad, 'offsetX' => $bad, 'offsetY' => $bad,
            ]);

            $this->assertNull($result, 'A value that cannot be drawn must not be kept.');
        }
    }

    /**
     * The dangerous shape: one good field carrying one unusable one.
     */
    public function test_a_bad_number_beside_a_good_one_drops_only_the_bad_one(): void
    {
        $result = MediaFraming::sanitize(['fit' => 'fit', 'scale' => NAN]);

        $this->assertSame(['fit' => 'fit'], $result);
        $this->assertArrayNotHasKey('scale', $result);
    }

    public function test_numbers_are_clamped_to_what_the_renderer_can_draw(): void
    {
        $result = MediaFraming::sanitize([
            'scale' => 9999, 'offsetX' => -50, 'offsetY' => 50,
        ]);

        $this->assertSame(MediaFraming::MAX_SCALE, $result['scale']);
        $this->assertSame(-1.0, $result['offsetX']);
        $this->assertSame(1.0, $result['offsetY']);
    }

    public function test_a_scale_below_one_is_raised_rather_than_dropped(): void
    {
        $result = MediaFraming::sanitize(['scale' => 0.01]);

        $this->assertSame(MediaFraming::MIN_SCALE, $result['scale']);
    }

    public function test_an_unknown_fit_or_aspect_is_dropped(): void
    {
        $this->assertNull(MediaFraming::sanitize(['fit' => 'sideways']));
        $this->assertNull(MediaFraming::sanitize(['aspect' => 'hexagonal']));
    }

    public function test_every_documented_aspect_survives(): void
    {
        foreach (MediaFraming::ASPECTS as $aspect) {
            $this->assertSame(
                ['aspect' => $aspect],
                MediaFraming::sanitize(['aspect' => $aspect]),
            );
        }
    }

    /** Stories nest it under `framing`; a post may send it flat. */
    public function test_both_the_nested_and_flat_shapes_are_accepted(): void
    {
        $nested = MediaFraming::sanitize(['framing' => ['fit' => 'fit']]);
        $flat = MediaFraming::sanitize(['fit' => 'fit']);

        $this->assertSame(['fit' => 'fit'], $nested);
        $this->assertSame($nested, $flat);
    }

    public function test_a_json_string_is_decoded_rather_than_refused(): void
    {
        $this->assertSame(
            ['fit' => 'fit'],
            MediaFraming::sanitize('{"fit":"fit"}'),
        );
    }

    public function test_malformed_json_becomes_null_rather_than_throwing(): void
    {
        $this->assertNull(MediaFraming::sanitize('{not json at all'));
    }

    public function test_a_numeric_string_is_still_a_number(): void
    {
        $result = MediaFraming::sanitize(['scale' => '2.0']);

        $this->assertSame(2.0, $result['scale']);
    }

    public function test_a_non_numeric_scale_is_dropped(): void
    {
        $this->assertNull(MediaFraming::sanitize(['scale' => 'huge']));
    }

    public function test_a_background_colour_is_masked_to_argb(): void
    {
        $result = MediaFraming::sanitize(['bg' => 0xFF14181F]);

        $this->assertSame(0xFF14181F, $result['bg']);
    }

    public function test_a_non_integer_background_is_dropped(): void
    {
        $this->assertNull(MediaFraming::sanitize(['bg' => 'red']));
        $this->assertNull(MediaFraming::sanitize(['bg' => 1.5]));
    }

    /**
     * Deeply nested junk is a cheap way to try to blow up a decoder.
     */
    public function test_nested_structures_where_numbers_belong_are_dropped(): void
    {
        $result = MediaFraming::sanitize([
            'scale' => ['deeply' => ['nested' => 1]],
            'fit' => ['fill'],
        ]);

        $this->assertNull($result);
    }
}
