<?php

namespace App\Services\Knowledge;

use Smalot\PdfParser\Parser;
use Throwable;

/** Extracts machine-readable PDF text, preserving reliable parser page boundaries. */
final class PdfTextExtractor
{
    /** @return array{text: string, title: ?string, pageCount: int, status: string, error: ?string} */
    public static function extract(string $bytes): array
    {
        try {
            $document = (new Parser)->parseContent($bytes);
            $details = $document->getDetails();
            $parts = [];

            foreach ($document->getPages() as $index => $page) {
                $text = self::clean($page->getText());
                if ($text !== '') {
                    // Kept in content/chunks so retrieval can cite a page only
                    // when the parser supplied an actual page boundary.
                    $parts[] = '[[PAGE '.($index + 1)."]]\n".$text;
                }
            }

            $text = implode("\n\n", $parts);
            $title = trim((string) ($details['Title'] ?? '')) ?: null;
            $pageCount = count($document->getPages());

            return [
                'text' => $text,
                'title' => $title,
                'pageCount' => $pageCount,
                'status' => $text === '' ? 'no_extractable_text' : 'indexed',
                'error' => $text === '' ? 'PDF contains no machine-readable text; OCR is not enabled.' : null,
            ];
        } catch (Throwable $e) {
            return [
                'text' => '',
                'title' => null,
                'pageCount' => 0,
                'status' => 'extraction_failed',
                'error' => mb_substr($e->getMessage(), 0, 500),
            ];
        }
    }

    private static function clean(string $text): string
    {
        $text = str_replace("\0", '', $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\R{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
