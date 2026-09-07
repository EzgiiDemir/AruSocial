<?php

namespace Tests\Unit;

use App\Support\UploadMagic;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class UploadMagicTest extends TestCase
{
    public function test_gd_jpeg_is_accepted(): void
    {
        $this->assertTrue(UploadMagic::isImage(
            UploadedFile::fake()->createWithContent(
                'a.jpg',
                "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00",
            ),
        ));
    }

    public function test_renamed_text_is_rejected(): void
    {
        $this->assertFalse(UploadMagic::isImage(
            UploadedFile::fake()->createWithContent('a.jpg', 'hello world'),
        ));
    }

    public function test_pdf_header_is_accepted(): void
    {
        $this->assertTrue(UploadMagic::isDocument(
            UploadedFile::fake()->createWithContent('cv.pdf', "%PDF-1.4\n%%EOF\n"),
        ));
    }

    public function test_empty_pdf_name_without_header_is_rejected(): void
    {
        $this->assertFalse(UploadMagic::isDocument(
            UploadedFile::fake()->create('cv.pdf', 20, 'application/pdf'),
        ));
    }
}
