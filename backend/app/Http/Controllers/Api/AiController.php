<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class AiController extends Controller
{
    use ApiResponds;

    // Real proxy, not a canned reply: if GROQ_API_KEY is set in the
    // backend's own environment, this genuinely calls Groq server-side —
    // the whole point (see docs/GERCEK_PROJEYE_GECIS.md §4/§18, the key
    // never ships inside the app). Without a key it says so plainly
    // instead of faking an answer.
    public function query(Request $request): JsonResponse
    {
        $apiKey = env('GROQ_API_KEY');
        if (! $apiKey) {
            return $this->fail(
                501,
                'AI_NOT_CONFIGURED',
                'GROQ_API_KEY is not set on the backend — set it in backend/.env to enable Ask ARUCAD through this server.'
            );
        }

        $prompt = (string) $request->input('prompt', '');

        try {
            $response = Http::withToken($apiKey)
                ->post('https://api.groq.com/openai/v1/chat/completions', [
                    'model' => 'openai/gpt-oss-120b',
                    'messages' => [['role' => 'user', 'content' => $prompt]],
                ]);
        } catch (\Throwable $e) {
            return $this->fail(502, 'AI_UPSTREAM_ERROR', (string) $e->getMessage());
        }

        if ($response->failed()) {
            return $this->fail(502, 'AI_UPSTREAM_ERROR', $response->json('error.message') ?? 'Groq request failed.');
        }

        $answer = $response->json('choices.0.message.content') ?? '';

        return $this->ok(['answer' => $answer]);
    }
}
