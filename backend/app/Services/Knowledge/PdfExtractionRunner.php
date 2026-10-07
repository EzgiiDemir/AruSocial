<?php

namespace App\Services\Knowledge;

use Symfony\Component\Process\Process;
use Throwable;

/** Runs the parser out-of-process so a pathological PDF cannot OOM the crawler. */
final class PdfExtractionRunner
{
    /** @return array{text: string, title: ?string, pageCount: int, status: string, error: ?string} */
    public function extract(string $bytes): array
    {
        try {
            $process = new Process([
                PHP_BINARY,
                '-d', 'memory_limit='.config('knowledge.pdf_parser_memory_limit', '192M'),
                base_path('scripts/extract-pdf.php'),
            ], base_path(), null, $bytes, (float) config('knowledge.pdf_parser_timeout', 30));
            $process->run();

            if (! $process->isSuccessful()) {
                return $this->failed('PDF parser worker failed: '.trim($process->getErrorOutput()));
            }

            $result = json_decode($process->getOutput(), true);
            if (! is_array($result) || ! isset($result['status'], $result['text'])) {
                return $this->failed('PDF parser worker returned malformed output.');
            }

            return [
                'text' => (string) $result['text'],
                'title' => isset($result['title']) ? (string) $result['title'] : null,
                'pageCount' => (int) ($result['pageCount'] ?? 0),
                'status' => (string) $result['status'],
                'error' => isset($result['error']) ? (string) $result['error'] : null,
            ];
        } catch (Throwable $e) {
            return $this->failed('PDF parser worker exception: '.$e->getMessage());
        }
    }

    /** @return array{text: string, title: null, pageCount: int, status: string, error: string} */
    private function failed(string $error): array
    {
        return [
            'text' => '', 'title' => null, 'pageCount' => 0,
            'status' => 'extraction_failed', 'error' => mb_substr($error, 0, 500),
        ];
    }
}
