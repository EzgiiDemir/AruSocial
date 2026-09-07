<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

// Poster → structured event draft fields. Uses Groq when GROQ_API_KEY is
// set; otherwise returns null so the controller can answer 501 honestly.
// Never publishes — callers always create draft/pending_review events.
class EventPosterDraftService
{
    public static function isConfigured(): bool
    {
        return (bool) config('services.groq.key');
    }

    /**
     * @return array{title: ?string, time: ?string, eventDate: ?string, placeName: ?string, category: ?string, organizer: ?string, description: ?string, incomplete: bool}|null
     */
    public static function extractFromImage(string $bytes, string $mimeType): ?array
    {
        $apiKey = (string) config('services.groq.key');
        if ($apiKey === '') {
            return null;
        }

        $dataUrl = "data:{$mimeType};base64,".base64_encode($bytes);
        $prompt = <<<'PROMPT'
Extract campus event details from this poster image. Reply with ONLY compact JSON keys:
title, time (HH:MM 24h if present), eventDate (YYYY-MM-DD if present), placeName, category, organizer, description.
Use null for unknown fields. Do not invent details that are not visible.
PROMPT;

        try {
            $response = Http::withToken($apiKey)
                ->timeout(45)
                ->withOptions(['verify' => (bool) config('services.groq.verify_ssl')])
                ->post('https://api.groq.com/openai/v1/chat/completions', [
                    'model' => (string) config('services.groq.vision_model'),
                    'messages' => [[
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => $prompt],
                            ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
                        ],
                    ]],
                    'temperature' => 0.1,
                ]);
        } catch (\Throwable) {
            return null;
        }

        if ($response->failed()) {
            return null;
        }

        $content = (string) ($response->json('choices.0.message.content') ?? '');
        if ($content === '') {
            return null;
        }

        if (preg_match('/\{.*\}/s', $content, $m)) {
            $content = $m[0];
        }

        $decoded = json_decode($content, true);
        if (! is_array($decoded)) {
            return null;
        }

        $title = self::nullableString($decoded['title'] ?? null);
        $time = self::nullableString($decoded['time'] ?? null);
        $eventDate = self::nullableString($decoded['eventDate'] ?? null);
        $placeName = self::nullableString($decoded['placeName'] ?? null);
        $category = self::nullableString($decoded['category'] ?? null);
        $organizer = self::nullableString($decoded['organizer'] ?? null);
        $description = self::nullableString($decoded['description'] ?? null);

        $incomplete = $title === null || $time === null || $placeName === null;

        return [
            'title' => $title,
            'time' => $time,
            'eventDate' => $eventDate,
            'placeName' => $placeName,
            'category' => $category,
            'organizer' => $organizer,
            'description' => $description,
            'incomplete' => $incomplete,
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '' || strcasecmp($trimmed, 'null') === 0) {
            return null;
        }

        return mb_substr($trimmed, 0, 500);
    }
}
