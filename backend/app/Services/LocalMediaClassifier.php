<?php

namespace App\Services;

use Symfony\Component\Process\Process;

/**
 * Adapter for ARUCAD-operated media classifiers.
 *
 * The configured executable runs on the same private host as Laravel. It
 * receives a temporary file path and returns JSON; no image, video, text or
 * student identity is sent to a third-party API. The executable can be a
 * versioned ONNX/PyTorch/TensorRT model runner owned by ARUCAD.
 *
 * Expected stdout (legacy score keys kept; policy codes accepted as aliases):
 * {"scores":{"nudity":0.02,"sexual_exploitation":0,"graphic_violence":0.97,"hate_symbol":0.01}}
 * {"scores":{"SEX":0.02,"CSA":0,"VIO":0.97,"HATE":0.01}}
 *
 * Mapping: nudity→SEX, sexual_exploitation→CSA, graphic_violence→VIO, hate_symbol→HATE.
 */
class LocalMediaClassifier
{
    /** Legacy score keys still emitted in categories[] for existing callers. */
    private const CATEGORIES = [
        'nudity',
        'sexual_exploitation',
        'graphic_violence',
        'hate_symbol',
    ];

    /** Policy codes → legacy key (also accepted directly in scores). */
    private const CODE_ALIASES = [
        'SEX' => 'nudity',
        'CSA' => 'sexual_exploitation',
        'VIO' => 'graphic_violence',
        'HATE' => 'hate_symbol',
    ];

    public static function isConfigured(): bool
    {
        $binary = config('services.local_moderation.binary');

        return is_string($binary) && trim($binary) !== '' && is_file($binary);
    }

    /** @return array{available: bool, blocked: bool, categories: list<string>, score: float} */
    public static function inspect(string $path, string $mimeType): array
    {
        $binary = config('services.local_moderation.binary');
        if (! self::isConfigured()) {
            return self::unavailable();
        }

        try {
            $process = new Process([$binary, '--input', $path, '--mime', $mimeType]);
            $process->setTimeout((int) config('services.local_moderation.timeout_seconds', 15));
            $process->run();
            if (! $process->isSuccessful()) {
                return self::unavailable();
            }
            $payload = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            $scores = is_array($payload['scores'] ?? null) ? $payload['scores'] : [];
            $threshold = (float) config('services.local_moderation.block_threshold', .92);
            $merged = self::mergeScores($scores);
            $categories = [];
            $highest = 0.0;
            foreach (self::CATEGORIES as $category) {
                $score = $merged[$category] ?? 0.0;
                $highest = max($highest, $score);
                if ($score >= $threshold) {
                    $categories[] = $category;
                }
            }

            return [
                'available' => true,
                'blocked' => $categories !== [],
                'categories' => $categories,
                'score' => $highest,
            ];
        } catch (\Throwable) {
            // A failed local model is fail-closed by the caller: media stays
            // pending and never becomes public. Do not leak runner output.
            return self::unavailable();
        }
    }

    /**
     * Fold policy-code scores into legacy keys without dropping either form.
     *
     * @param  array<mixed>  $scores
     * @return array<string, float>
     */
    private static function mergeScores(array $scores): array
    {
        $merged = [];
        foreach (self::CATEGORIES as $legacy) {
            $score = $scores[$legacy] ?? 0;
            $merged[$legacy] = is_numeric($score) ? max(0.0, min(1.0, (float) $score)) : 0.0;
        }
        foreach (self::CODE_ALIASES as $code => $legacy) {
            $score = $scores[$code] ?? null;
            if (! is_numeric($score)) {
                continue;
            }
            $merged[$legacy] = max($merged[$legacy], max(0.0, min(1.0, (float) $score)));
        }

        return $merged;
    }

    /** @return array{available: false, blocked: false, categories: list<string>, score: float} */
    private static function unavailable(): array
    {
        return ['available' => false, 'blocked' => false, 'categories' => [], 'score' => 0.0];
    }
}
