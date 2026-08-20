<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Http;

// The server-side half of docs/EKSIKLER.md §26: this used to be a direct
// OpenAI call made from the Flutter client, with the API key sitting in
// on-device SharedPreferences — meaning the key shipped to (and the raw
// external call was made from) every device that had it configured. Same
// real check, same real consequence (a flagged image is a real strike via
// ModerationService::recordImageStrike), but now the key never leaves the
// server and the call is made from here.
//
// Policy, unchanged from the client-side version it replaces:
// - No key configured (AppSetting 'moderation.apiKey') → skipped entirely,
//   the image is accepted as-is.
// - The API call itself fails (network, invalid key, quota) → fails
//   *open* — a moderation-provider outage shouldn't silently block every
//   upload.
// - The API call succeeds and flags the image → fails *closed*, rejected
//   with a real reason, and counts as a real strike.
class ImageModerationService
{
    private const ENDPOINT = 'https://api.openai.com/v1/moderations';
    private const MODEL = 'omni-moderation-latest';

    public static function isConfigured(): bool
    {
        return (bool) AppSetting::getValue('moderation.apiKey');
    }

    /**
     * Checks the given image bytes. Returns null if clean/skipped, or the
     * flagged category name (e.g. "sexual") if the image was rejected —
     * the caller decides what to do with that (ModerationController
     * records the strike and returns CONTENT_BLOCKED).
     */
    public static function checkImageBytes(string $bytes, string $mimeType): ?string
    {
        $apiKey = AppSetting::getValue('moderation.apiKey');
        if (! $apiKey) {
            return null;
        }

        $dataUrl = "data:{$mimeType};base64,".base64_encode($bytes);

        try {
            $response = Http::withToken($apiKey)
                ->timeout(15)
                ->post(self::ENDPOINT, [
                    'model' => self::MODEL,
                    'input' => [
                        ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
                    ],
                ]);
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $results = $response->json('results');
        if (! is_array($results) || empty($results)) {
            return null;
        }
        $result = $results[0];
        if (! ($result['flagged'] ?? false)) {
            return null;
        }

        $categories = $result['categories'] ?? [];
        foreach ($categories as $category => $hit) {
            if ($hit === true) {
                return $category;
            }
        }

        return 'uygunsuz içerik';
    }
}
