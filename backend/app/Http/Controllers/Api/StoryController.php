<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\Story;
use App\Services\ModerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoryController extends Controller
{
    use ApiResponds;

    private function storyToJson(Story $s): array
    {
        return [
            'id' => $s->id,
            'authorId' => (string) $s->author_id,
            'authorName' => $s->author_name,
            'text' => $s->text,
            'visibility' => $s->visibility,
            'createdAt' => $s->created_at?->toIso8601String(),
        ];
    }

    public function index(): JsonResponse
    {
        $cutoff = now()->subDay();
        $stories = Story::where('created_at', '>=', $cutoff)->orderByDesc('created_at')->get();

        return $this->ok($stories->map(fn ($s) => $this->storyToJson($s)));
    }

    public function store(Request $request): JsonResponse
    {
        $me = $this->currentUser();
        $text = $request->input('text');
        if ($text !== null && ($blocked = ModerationService::checkText($me, $text))) {
            return $this->fail(400, 'CONTENT_BLOCKED', $blocked);
        }
        $story = Story::create([
            'id' => $this->newId('story'),
            'author_id' => $me->id,
            'author_name' => $me->name,
            'text' => $text,
            'background_color_value' => $request->input('backgroundColorValue'),
            'visibility' => $request->input('visibility') === 'onlyMe' ? 'onlyMe' : 'everyone',
            'created_at' => now(),
        ]);

        return $this->ok($this->storyToJson($story));
    }
}
