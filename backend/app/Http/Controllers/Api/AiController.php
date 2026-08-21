<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\AskArucadLog;
use App\Services\AskArucadCategorizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

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
        $prompt = (string) $request->input('prompt', '');
        // Real question logging (docs/EKSIKLER.md admin §5) — logged
        // regardless of whether Groq is even configured or the upstream
        // call succeeds: the analytics question is "what do students ask
        // Ask ARUCAD", not "what did Groq successfully answer".
        if (trim($prompt) !== '') {
            AskArucadLog::create([
                'id' => 'asklog-'.Str::uuid(),
                'user_id' => $this->currentUser()->id,
                'question' => $prompt,
                'category' => AskArucadCategorizer::categorize($prompt),
                'created_at' => now(),
            ]);
        }

        $apiKey = env('GROQ_API_KEY');
        if (! $apiKey) {
            return $this->fail(
                501,
                'AI_NOT_CONFIGURED',
                'GROQ_API_KEY is not set on the backend — set it in backend/.env to enable Ask ARUCAD through this server.'
            );
        }

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
