<?php

namespace Tests\Feature;

use App\Services\ImageModerationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every image format a phone actually produces has to be accepted.
 *
 * The validity of an upload used to depend on the caller's declared MIME
 * type matching the file's signature. The client wrapper never passed one,
 * so everything was validated as JPEG — and a PNG, which is what a
 * screenshot or a web file picker gives you, was refused as "geçersiz
 * görsel verisi". Students simply could not post pictures.
 *
 * The format is now read from the header, which fixes the mismatch and is
 * the safer check anyway: a declared type is attacker-controlled, a file's
 * own magic bytes are not.
 */
class ImageFormatAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, string> */
    private function samples(): array
    {
        return [
            'jpeg' => "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00".str_repeat('A', 64),
            'png' => "\x89PNG\r\n\x1A\n".str_repeat('A', 64),
            'gif' => 'GIF89a'.str_repeat('A', 64),
            'webp' => 'RIFF'."\x00\x00\x00\x00".'WEBP'.str_repeat('A', 64),
            // What an iPhone produces by default.
            'heic' => "\x00\x00\x00\x18".'ftyp'.'heic'.str_repeat('A', 64),
        ];
    }

    public function test_every_supported_format_is_accepted_whatever_the_caller_claims(): void
    {
        $rejected = [];
        foreach ($this->samples() as $name => $bytes) {
            // Deliberately declaring the wrong type: the header should win.
            foreach (['image/jpeg', 'image/png', '', 'application/octet-stream'] as $claimed) {
                if (ImageModerationService::checkImageBytes($bytes, $claimed) !== null) {
                    $rejected[] = "$name declared as '$claimed'";
                }
            }
        }

        $this->assertSame([], $rejected,
            'Valid images refused: '.implode(', ', $rejected));
    }

    public function test_the_preflight_endpoint_accepts_a_png(): void
    {
        $this->actingAsUser();

        // The exact failure students hit: pick a PNG, get "invalid image".
        $this->postJson('/api/v1/moderation/check-image', [
            'imageBase64' => base64_encode($this->samples()['png']),
        ])->assertOk()->assertJsonPath('data.allowed', true);
    }

    public function test_things_that_are_not_images_are_still_refused(): void
    {
        $notImages = [
            'executable' => 'MZ'.str_repeat('A', 64),
            'pdf' => '%PDF-1.7'.str_repeat('A', 64),
            'script' => '<?php echo 1;',
            'empty' => '',
        ];

        foreach ($notImages as $name => $bytes) {
            $this->assertNotNull(
                ImageModerationService::checkImageBytes($bytes, 'image/jpeg'),
                "$name was accepted as an image.",
            );
        }
    }

    public function test_sniffing_reports_the_real_format(): void
    {
        $expected = [
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'heic' => 'image/heic',
        ];

        foreach ($this->samples() as $name => $bytes) {
            $this->assertSame($expected[$name], ImageModerationService::sniffFormat($bytes));
        }
        $this->assertNull(ImageModerationService::sniffFormat('MZ'.str_repeat('A', 32)));
    }
}
