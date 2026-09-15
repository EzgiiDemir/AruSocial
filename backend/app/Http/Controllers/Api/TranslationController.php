<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\TranslationKey;
use App\Services\Translations\TranslationCatalogue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Published strings, for the app.
 *
 * Deliberately unauthenticated: the sign-in screen needs its own words
 * before anybody has a token, and these are the public-facing labels of a
 * public-facing app — not data worth a session. Nothing here is
 * user-specific, which is also why the whole response is cacheable.
 *
 * What makes checking on every launch affordable is the ETag: the phone
 * sends the version it holds, and an unchanged catalogue answers 304 with
 * no body.
 */
class TranslationController extends Controller
{
    use ApiResponds;

    public function index(Request $request): JsonResponse
    {
        $locale = (string) $request->query('lang', TranslationKey::FALLBACK);
        if (! in_array($locale, TranslationKey::LOCALES, true)) {
            $locale = TranslationKey::FALLBACK;
        }

        $version = TranslationCatalogue::version();
        $etag = '"'.$locale.'-'.$version.'"';

        // Answer before assembling anything: the common case is a phone
        // that already has the current strings, and it should cost a
        // header comparison rather than a query.
        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return response()->json(null, 304)->withHeaders([
                'ETag' => $etag,
                'Cache-Control' => 'public, max-age=300',
            ]);
        }

        return $this->ok([
            'locale' => $locale,
            'version' => $version,
            'fallback' => TranslationKey::FALLBACK,
            'strings' => TranslationCatalogue::for($locale),
        ])->withHeaders([
            'ETag' => $etag,
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
