<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\Story;
use App\Models\StoryView;
use App\Services\ModerationService;
use App\Support\MediaPublicUrl;
use App\Support\SocialAudience;
use App\Support\SocialMediaAttachment;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoryController extends Controller
{
    use ApiResponds;

    private function storyToJson(Story $s, int $viewerId, array $viewedIds): array
    {
        return [
            'id' => $s->id,
            'authorId' => (string) $s->author_id,
            'authorName' => $s->author_name,
            'text' => $s->text,
            'imageUrl' => MediaPublicUrl::rewrite($s->image_url),
            'mediaMimeType' => $s->media_mime_type,
            // Real bug fix: this was stored on every create but never
            // returned, so every story rendered with no background color
            // regardless of what was picked when composing it.
            'backgroundColorValue' => $s->background_color_value,
            'style' => $s->style_json,
            'visibility' => $s->visibility,
            'viewCount' => (int) ($s->views_count ?? $s->views()->count()),
            // Own stories are always "seen"; otherwise this reflects a real
            // StoryView row, not a client-side SharedPreferences guess that
            // resets on every fresh install/device.
            'viewedByMe' => (int) $s->author_id === $viewerId
                || in_array($s->id, $viewedIds, true),
            'createdAt' => $s->created_at?->toIso8601String(),
        ];
    }

    public function index(): JsonResponse
    {
        $me = $this->currentUser();
        $cutoff = now()->subDay();
        $stories = Story::where('created_at', '>=', $cutoff);
        SocialAudience::constrainStories($stories, $me);
        $stories = $stories
            ->where(function ($q) use ($me) {
                $q->where('author_id', $me->id)
                    ->orWhereDoesntHave('user', fn ($u) => $u->where('is_private_profile', true))
                    ->orWhereHas('user', function ($u) use ($me) {
                        $u->where('is_private_profile', true)
                            ->whereHas('followers', fn ($f) => $f->where('users.id', $me->id));
                    });
            })
            ->withCount('views')
            ->orderByDesc('created_at')
            ->get();

        $viewedIds = StoryView::query()
            ->whereIn('story_id', $stories->pluck('id'))
            ->where('viewer_user_id', $me->id)
            ->pluck('story_id')
            ->all();

        return $this->ok($stories->map(fn ($s) => $this->storyToJson($s, (int) $me->id, $viewedIds)));
    }

    public function store(Request $request): JsonResponse
    {
        $me = $this->currentUser();
        $text = $request->input('text');
        if ($text !== null && ($blocked = ModerationService::checkText($me, $text))) {
            return $this->fail(400, 'CONTENT_BLOCKED', $blocked);
        }
        $attachment = SocialMediaAttachment::resolve($request->input('imageUrl'), $me);
        if ($attachment['code'] !== null) {
            return $this->fail(422, $attachment['code'], $attachment['message']);
        }
        $story = Story::create([
            'id' => $this->newId('story'),
            'author_id' => $me->id,
            'author_name' => $me->name,
            'text' => $text,
            'image_url' => $attachment['url'],
            'media_mime_type' => $attachment['item']?->mime_type,
            'background_color_value' => $request->input('backgroundColorValue'),
            'style_json' => is_array($request->input('style')) ? $request->input('style') : null,
            'visibility' => SocialAudience::visibility($request->input('visibility')),
            'created_at' => now(),
        ]);
        $story->views_count = 0;

        return $this->ok($this->storyToJson($story, (int) $me->id, []));
    }

    public function view(string $id): JsonResponse
    {
        $me = $this->currentUser();
        $story = Story::query()->whereKey($id)->first();
        if (! $story) {
            return $this->fail(404, 'STORY_NOT_FOUND', 'Story not found.');
        }

        // Own views are ignored so viewCount stays "other people who saw it".
        if ((int) $story->author_id !== (int) $me->id) {
            try {
                StoryView::query()->firstOrCreate(
                    [
                        'story_id' => $story->id,
                        'viewer_user_id' => $me->id,
                    ],
                    ['viewed_at' => now()],
                );
            } catch (QueryException $e) {
                if (! str_contains($e->getMessage(), 'UNIQUE') && $e->getCode() !== '23000') {
                    throw $e;
                }
            }
        }

        return $this->ok(['viewed' => true]);
    }

    public function viewers(string $id): JsonResponse
    {
        $me = $this->currentUser();
        $story = Story::query()->whereKey($id)->where('author_id', $me->id)->first();
        if (! $story) {
            return $this->fail(404, 'STORY_NOT_FOUND', 'Story not found.');
        }

        $rows = StoryView::query()
            ->where('story_id', $story->id)
            ->with('viewer')
            ->orderByDesc('viewed_at')
            ->get();

        return $this->ok($rows->map(fn (StoryView $v) => [
            'name' => $v->viewer?->name,
            'avatarUrl' => $v->viewer?->avatar_url,
            'viewedAt' => $v->viewed_at?->toIso8601String(),
        ])->values());
    }

    // Stories are ephemeral (24h), so only deletion is offered — not
    // edit — matching how every real story-format product treats them.
    public function destroy(string $id): JsonResponse
    {
        $me = $this->currentUser();
        $story = Story::where('id', $id)->where('author_id', $me->id)->first();
        if (! $story) {
            return $this->fail(404, 'STORY_NOT_FOUND', 'Story not found.');
        }
        $story->delete();

        return $this->ok(['deleted' => true]);
    }
}
